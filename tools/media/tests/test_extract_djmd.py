import pathlib
import struct
import sys
import tempfile
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[3]
DJMD_DIR = ROOT / "tools/media/panoforge/core/osv_meta"
sys.path.insert(0, str(DJMD_DIR))

import extract_djmd  # noqa: E402
from tools.media.panoforge.core.maps import generate_remap_maps  # noqa: E402


def _varint(value):
    encoded = bytearray()
    while value > 0x7F:
        encoded.append((value & 0x7F) | 0x80)
        value >>= 7
    encoded.append(value)
    return bytes(encoded)


def _fixed32(field, value):
    return _varint((field << 3) | 5) + struct.pack("<f", value)


def _bytes(field, value):
    return _varint((field << 3) | 2) + _varint(len(value)) + value


def _lens(*, quaternion_field, nested_quaternion=False, zero_luts=False, yaw=179.6, quaternion=None):
    payload = b"".join(
        _fixed32(field, value)
        for field, value in {
            1: 1042.0,
            2: 1043.0,
            3: 1919.0,
            4: 1914.0,
            5: 0.08,
            6: -0.03,
            7: 0.02,
            8: -0.01,
            10: 3840.0,
            11: 3840.0,
            12: yaw,
            13: 90.1,
        }.items()
    )
    quaternion = quaternion or [1.0, 0.1, 0.2, 0.3]
    if nested_quaternion:
        quaternion_payload = b"".join(
            _fixed32(field, value)
            for field, value in enumerate(quaternion, start=1)
        )
    else:
        quaternion_payload = struct.pack("<4f", *quaternion)
    payload += _bytes(quaternion_field, quaternion_payload)

    lut = [0.0] * 14 if zero_luts else [float(i) for i in range(14)]
    payload += _bytes(22, struct.pack("<14f", *lut))
    payload += _bytes(23, struct.pack("<14f", *lut))
    return payload


class CalibrationDecoderTests(unittest.TestCase):
    def test_decodes_osmo_calibration_layout(self):
        calibration = _bytes(1, _lens(quaternion_field=21, yaw=179.6))
        calibration += _bytes(2, _lens(quaternion_field=21, yaw=-0.4))
        sample = _bytes(2, _bytes(6, calibration))

        decoded = extract_djmd.decode_calibration(sample)

        self.assertEqual(len(decoded["lenses"]), 2)
        for actual, expected in zip(decoded["lenses"][0]["extrinsic_quat"], [1.0, 0.1, 0.2, 0.3]):
            self.assertAlmostEqual(actual, expected)
        self.assertIn("radial_lut_1", decoded["lenses"][0])

    def test_rejects_incomplete_calibration_instead_of_passing_one_lens_to_maps(self):
        calibration = _bytes(1, _lens(quaternion_field=21))
        sample = _bytes(2, _bytes(6, calibration))

        decoded = extract_djmd.decode_calibration(sample)

        self.assertEqual(decoded["lenses"], [])

    def test_osmo_uses_first_two_usable_lenses_when_metadata_has_extras(self):
        calibration = _bytes(1, _lens(quaternion_field=21, yaw=179.6))
        calibration += _bytes(2, _lens(quaternion_field=21, yaw=-0.4))
        calibration += _bytes(3, _lens(quaternion_field=21, yaw=45.0))

        decoded = extract_djmd.decode_calibration(_bytes(2, _bytes(6, calibration)))

        self.assertEqual(len(decoded["lenses"]), 2)
        self.assertAlmostEqual(decoded["lenses"][0]["yaw_deg"], 179.6, places=3)
        self.assertAlmostEqual(decoded["lenses"][1]["yaw_deg"], -0.4, places=3)

    def test_two_distinct_lenses_flow_through_map_generation(self):
        avata = _bytes(3, _lens(
            quaternion_field=26, nested_quaternion=True, zero_luts=True,
            yaw=179.6, quaternion=[1.0, 0.0, 0.0, 0.0],
        ))
        avata += _bytes(4, _lens(
            quaternion_field=26, nested_quaternion=True, zero_luts=True,
            yaw=-0.4, quaternion=[0.0, 0.0, 0.0, 1.0],
        ))
        decoded = extract_djmd.decode_calibration(_bytes(2, _bytes(5, avata)))

        with tempfile.TemporaryDirectory() as workdir:
            maps = generate_remap_maps(decoded, 16, 8, workdir)
            first_map = pathlib.Path(maps.xmaps[0]).read_bytes()
            second_map = pathlib.Path(maps.xmaps[1]).read_bytes()

        self.assertTrue(maps.calibrated)
        self.assertEqual(len(maps.xmaps), 2)
        self.assertNotEqual(first_map, second_map)

    def test_decodes_avata360_calibration_layout(self):
        avata = _bytes(3, _lens(quaternion_field=26, nested_quaternion=True, zero_luts=True))
        avata += _bytes(4, _lens(quaternion_field=26, nested_quaternion=True, zero_luts=True))
        sample = _bytes(2, _bytes(5, avata))

        decoded = extract_djmd.decode_calibration(sample)

        self.assertEqual(len(decoded["lenses"]), 2)
        self.assertEqual(decoded["lenses"][0]["width"], 3840.0)
        for actual, expected in zip(decoded["lenses"][0]["extrinsic_quat"], [1.0, 0.1, 0.2, 0.3]):
            self.assertAlmostEqual(actual, expected)
        self.assertNotIn("radial_lut_1", decoded["lenses"][0])
        self.assertNotIn("radial_lut_2", decoded["lenses"][0])

    def test_avata360_ignores_zero_legacy_quaternion_and_uses_nested_quaternion(self):
        legacy_placeholder = _bytes(21, struct.pack("<4f", 0.0, 0.0, 0.0, 0.0))
        front = legacy_placeholder + _lens(
            quaternion_field=26,
            nested_quaternion=True,
            zero_luts=True,
            quaternion=[-0.0064, -0.0014, 0.0021, -0.9999],
        )
        back = legacy_placeholder + _lens(
            quaternion_field=26,
            nested_quaternion=True,
            zero_luts=True,
            quaternion=[0.9999, 0.0065, 0.0015, -0.0061],
        )

        decoded = extract_djmd.decode_calibration(
            _bytes(2, _bytes(5, _bytes(3, front) + _bytes(4, back)))
        )

        self.assertEqual(len(decoded["lenses"]), 2)
        for actual, expected in zip(
            decoded["lenses"][0]["extrinsic_quat"],
            [-0.0064, -0.0014, 0.0021, -0.9999],
        ):
            self.assertAlmostEqual(actual, expected, places=4)

    def test_subfloats_ignores_non_fixed32_fields(self):
        payload = _varint(1 << 3) + _varint(42)

        self.assertEqual(extract_djmd.subfloats(payload, (1, 2)), [None, None])

    def test_perframe_ignores_changed_optional_sensor_field(self):
        sensor = _bytes(1, b"not-a-fixed32")
        payload = _bytes(3, _bytes(2, _bytes(16, sensor)))

        row, highrate = extract_djmd.decode_perframe(payload)

        self.assertEqual(row, {})
        self.assertEqual(highrate, [])


if __name__ == "__main__":
    unittest.main()
