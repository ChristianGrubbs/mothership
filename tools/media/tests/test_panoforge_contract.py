import json
import pathlib
import sys
import tempfile
import unittest

import numpy as np

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[3]))

from tools.media.panoforge.core.maps import INVALID, generate_remap_maps
from tools.media.panoforge.core.stitch import StitchOptions, build_command

ROOT = pathlib.Path(__file__).resolve().parents[3]

OUT_W = 3840
OUT_H = 1920
YAW_OFFSET_DEG = 90.0  # must match maps.py's YAW_OFFSET_DEG (checked independently below)


def _read_pgm(path):
    """Minimal binary-PGM (P5) reader, independent of maps.py's own writer,
    so a bug in `_write_pgm` cannot hide behind a symmetrical bug here."""
    with open(path, "rb") as f:
        magic = f.readline().strip()
        if magic != b"P5":
            raise ValueError(f"not a binary PGM: {magic!r}")
        dims = f.readline().split()
        w, h = int(dims[0]), int(dims[1])
        maxval = int(f.readline())
        dtype = ">u2" if maxval > 255 else "u1"
        data = np.frombuffer(f.read(), dtype=dtype).reshape(h, w)
    return data, maxval


def _quat_to_rot(q):
    """Standard quaternion [w,x,y,z] -> 3x3 rotation matrix (v' = R @ v),
    written independently from maps.py's implementation. This is the
    textbook formula, not a copy of the vendored code, so it lets the test
    check the vendored code's output against known geometry rather than
    against itself."""
    w, x, y, z = q
    n = (w * w + x * x + y * y + z * z) ** 0.5
    w, x, y, z = w / n, x / n, y / n, z / n
    return np.array([
        [1 - 2 * (y * y + z * z), 2 * (x * y - w * z), 2 * (x * z + w * y)],
        [2 * (x * y + w * z), 1 - 2 * (x * x + z * z), 2 * (y * z - w * x)],
        [2 * (x * z - w * y), 2 * (y * z + w * x), 1 - 2 * (x * x + y * y)],
    ])


def _boresight_pixel(lens, out_w=OUT_W, out_h=OUT_H):
    """Given a lens's extrinsic_quat, derive the (row, col) pixel of the
    output equirectangular frame that looks straight down that lens's own
    optical axis (theta == 0), independently of maps.py's internal loop —
    this is the same body/lens frame convention documented in maps.py's
    module docstring (X=right, Y=front, Z=up; lon includes +90 deg yaw
    offset), re-derived here from first principles rather than imported.
    """
    r = _quat_to_rot(lens["extrinsic_quat"])
    # d_lens = R @ d_body, and R is orthonormal, so d_body = R.T @ d_lens.
    # The lens optical axis is d_lens = [0, 0, 1] (theta = 0 there).
    d_body = r.T @ np.array([0.0, 0.0, 1.0])
    x_body, y_body, z_body = d_body
    lat = np.arcsin(np.clip(z_body, -1.0, 1.0))
    lon = np.arctan2(x_body, y_body)  # d = [cl*sin(lon), cl*cos(lon), sl]

    # Invert maps.py's per-column longitude formula:
    #   lon_col = ((col + 0.5) / out_w * 2*pi - pi) + radians(YAW_OFFSET_DEG)
    lon_shifted = lon - np.radians(YAW_OFFSET_DEG)
    # wrap into (-pi, pi] before mapping back to a column index
    lon_shifted = (lon_shifted + np.pi) % (2 * np.pi) - np.pi
    col = (lon_shifted + np.pi) / (2 * np.pi) * out_w - 0.5

    # Invert maps.py's per-row latitude formula:
    #   lat_row = pi/2 - (row + 0.5) / out_h * pi
    row = (np.pi / 2 - lat) / np.pi * out_h - 0.5

    col = int(round(col)) % out_w
    row = int(round(row))
    row = max(0, min(out_h - 1, row))
    return row, col


class PanoForgeContractTest(unittest.TestCase):
    def setUp(self):
        self.calibration = json.loads(
            (ROOT / "tools/media/tests/fixtures/calibration.json").read_text()
        )
        self.work = tempfile.TemporaryDirectory()
        self.addCleanup(self.work.cleanup)
        self.maps = generate_remap_maps(self.calibration, OUT_W, OUT_H, self.work.name)
        self.lens0 = self.calibration["lenses"][0]
        self.lens1 = self.calibration["lenses"][1]

    def test_provenance_pin(self):
        provenance = json.loads((ROOT / "tools/media/panoforge/UPSTREAM.json").read_text())
        self.assertEqual(provenance["commit"], "5bcfbe8a9d5b59746a80529ef2fa75d3999e1909")

    def test_calibrated_flag_set_from_real_lens_data(self):
        self.assertIs(self.maps.calibrated, True)

    def test_seam_graph_wiring(self):
        command = build_command(
            "/tmp/input.osv", "/tmp/output.mp4",
            StitchOptions(out_w=OUT_W, codec="h264", encoder="cpu", mode="calibrated"),
            self.maps,
        )
        graph = command[command.index("-filter_complex") + 1]
        self.assertEqual(graph.count("remap"), 2)
        self.assertIn("maskedmerge", graph)
        self.assertIn("libx264", command)
        self.assertIn("yuv420p", graph)

    def test_generated_maps_have_expected_shape_and_dtype(self):
        for path in (*self.maps.xmaps, *self.maps.ymaps):
            data, maxval = _read_pgm(path)
            self.assertEqual(data.shape, (OUT_H, OUT_W))
            self.assertEqual(maxval, INVALID)
            self.assertEqual(data.dtype, np.dtype(">u2"))
        mask, mask_maxval = _read_pgm(self.maps.blend_mask)
        self.assertEqual(mask.shape, (OUT_H, OUT_W))
        self.assertEqual(mask_maxval, 255)
        self.assertEqual(mask.dtype, np.dtype("u1"))

    def test_the_two_lenses_reproject_differently(self):
        x0, _ = _read_pgm(self.maps.xmaps[0])
        x1, _ = _read_pgm(self.maps.xmaps[1])
        y0, _ = _read_pgm(self.maps.ymaps[0])
        y1, _ = _read_pgm(self.maps.ymaps[1])
        # Identical fisheye->equirect maps for two physically distinct lenses
        # would mean the calibration was never actually consulted.
        self.assertFalse(np.array_equal(x0, x1))
        self.assertFalse(np.array_equal(y0, y1))

    def test_boresight_pixel_matches_own_lens_and_excludes_other_lens(self):
        """Derive, from the fixture's own extrinsic_quat (independently of
        maps.py), the output pixel that looks straight down each lens's
        optical axis. At that pixel:
          - that lens's own remap coordinate must land near its (cx, cy)
            (theta ~= 0 there, so the fisheye radius ~= 0).
          - the OTHER lens (facing ~180 deg away) must be marked INVALID,
            since it is far outside FOV_HALF_DEG (96 deg) at that point.
        This is only possible to assert correctly if the vendored code is
        actually consuming the fixture's per-lens fx/cx/cy/extrinsic_quat,
        not just branching on `bool(calibration.get("lenses"))`.
        """
        x0, _ = _read_pgm(self.maps.xmaps[0])
        y0, _ = _read_pgm(self.maps.ymaps[0])
        x1, _ = _read_pgm(self.maps.xmaps[1])
        y1, _ = _read_pgm(self.maps.ymaps[1])

        row0, col0 = _boresight_pixel(self.lens0)
        row1, col1 = _boresight_pixel(self.lens1)

        # A pixel-grid quantization step (~0.094 deg/px at this resolution)
        # keeps the sampled column/row a fraction of a degree off the exact
        # boresight, so the projected radius there is small but not exactly
        # zero. 15 px is generous slack for that plus float rounding.
        TOL_PX = 15.0

        self.assertNotEqual(int(x0[row0, col0]), INVALID)
        self.assertNotEqual(int(y0[row0, col0]), INVALID)
        self.assertLess(abs(float(x0[row0, col0]) - self.lens0["cx"]), TOL_PX)
        self.assertLess(abs(float(y0[row0, col0]) - self.lens0["cy"]), TOL_PX)
        # Lens 1 (facing ~180 deg away from lens 0's boresight) must be
        # excluded by the field-of-view cutoff at that same pixel.
        self.assertEqual(int(x1[row0, col0]), INVALID)
        self.assertEqual(int(y1[row0, col0]), INVALID)

        self.assertNotEqual(int(x1[row1, col1]), INVALID)
        self.assertNotEqual(int(y1[row1, col1]), INVALID)
        self.assertLess(abs(float(x1[row1, col1]) - self.lens1["cx"]), TOL_PX)
        self.assertLess(abs(float(y1[row1, col1]) - self.lens1["cy"]), TOL_PX)
        self.assertEqual(int(x0[row1, col1]), INVALID)
        self.assertEqual(int(y0[row1, col1]), INVALID)


if __name__ == "__main__":
    unittest.main()
