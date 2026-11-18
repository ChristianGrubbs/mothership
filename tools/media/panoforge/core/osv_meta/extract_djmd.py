#!/usr/bin/env python3
"""
Extract IMU (orientation quaternion + accelerometer), exposure metadata and
optical calibration from a DJI Osmo 360 .OSV/.MP4 file (djmd metadata track,
protobuf dvtm_oq101.proto).

Outputs:
  imu_perframe.csv    : one row per video frame - timestamp, quaternion, accel, ISO, shutter, colortemp
  imu_highrate.csv    : ~1 kHz orientation quaternion stream
  calibration.json    : per-lens intrinsics/distortion/extrinsics + global header

Usage: python3 extract_djmd.py <file.OSV> [outdir]

Protobuf field map (reverse-engineered, Osmo 360 fw 10.00.25.29 / proto 2.0.8):
  Per-frame metadata sample (djmd track, handler 'CAM meta', ~1 kB):
    top #3 -> payload
       .#1  header: (#1 type, #2 timestamp_us_like)
       .#2  frame metadata:
            #3.#1  ISO (float)
            #4.#1  shutter (2 bytes, e.g. 01 64 -> 1/356? exiftool reads 1/100)
            #6.#1  color temperature (varint, Kelvin)
            #9     orientation quaternion (4x float32, normalized) [w,x,y,z order TBD]
            #10    accelerometer (3x float32, units of g)  fields #2,#3,#4
            #15    exposure/AE block (7 floats: EV, ?, gains..., + flag)
            #16.#1 sensor temperature (float, deg C)
       .#3  high-rate IMU block:
            ...#1.#1 block start timestamp
            ...#1 repeated #3 = orientation quaternion (4x float32) ~40/frame (~1 kHz)
  First sample of djmd track (~7.7 kB) additionally carries:
    top #1  device header (proto name, fw, serial, model 'Osmo 360', boot ts)
        #1.#3 initial orientation quaternion (4 floats)
    top #2 .#6  optical calibration: up to 16 lens blocks, first 2 populated:
       #1  fx (float, px)          #2  fy (float, px)
       #3  cx (float, px)          #4  cy (float, px)
       #5..#8 distortion coeffs (k1,k2,p1,p2-like)
       #10 img width  #11 img height
       #12 lens yaw(deg) #13 lens pitch(deg)
       #21 lens extrinsic quaternion (4 floats)  (== #28)
       #22 radial-distortion LUT (14 floats, field->radius)
       #23 radial-distortion LUT (14 floats)
"""
import sys, os, struct, json, csv, math
from mp4parse import walk, parse_trak, sample_offsets

def read_varint(b,i):
    shift=0;r=0
    while True:
        c=b[i];i+=1;r|=(c&0x7f)<<shift
        if not c&0x80:break
        shift+=7
    return r,i

def parse_fields(b):
    """Return dict field->list of (wiretype,value_bytes/number)."""
    i=0;n=len(b);out={}
    while i<n:
        tag,i=read_varint(b,i)
        f=tag>>3;wt=tag&7
        if wt==0: v,i=read_varint(b,i)
        elif wt==5: v=b[i:i+4];i+=4
        elif wt==1: v=b[i:i+8];i+=8
        elif wt==2:
            ln,i=read_varint(b,i);v=b[i:i+ln];i+=ln
        else: break
        out.setdefault(f,[]).append((wt,v))
    return out

def f32(v): return struct.unpack('<f',v)[0]
def floats(b): return list(struct.unpack('<%df'%(len(b)//4),b))
def subfloats(b, keys):
    """Parse a sub-message and return [f32(field k) for k in keys], None if absent."""
    fl=parse_fields(b)
    out=[]
    for k in keys:
        entries=fl.get(k,[])
        if not entries or entries[0][0]!=5 or not isinstance(entries[0][1],bytes) or len(entries[0][1])!=4:
            out.append(None)
        else:
            out.append(f32(entries[0][1]))
    return out

def submessage(fields,key):
    entries=fields.get(key,[])
    if not entries or entries[0][0]!=2 or not isinstance(entries[0][1],bytes):
        return None
    return entries[0][1]

def get_traks(data):
    tl=[]
    walk(data,0,len(data),lambda t,o,s,h,d: tl.append((o,s)) if t==b'trak' else None)
    res=[]
    for o,s in tl:
        info=parse_trak(data,o,s)
        info['samples']=sample_offsets(info)
        res.append(info)
    return res

def find_djmd_full(traks):
    """The djmd track whose first sample is large (contains calibration+IMU)."""
    cand=[]
    for t in traks:
        if t['codec']==b'djmd' and t['samples']:
            cand.append((t['samples'][0][1],t))  # size of first sample
    cand.sort(key=lambda x:x[0],reverse=True)
    return cand[0][1] if cand else None

def decode_perframe(payload):
    """payload = bytes of the per-frame protobuf sample."""
    top=parse_fields(payload)
    row={}
    # frame metadata lives under top #3 -> .#2 ; high-rate under #3
    if 3 not in top: return None,[]
    inner_payload=submessage(top,3)
    if inner_payload is None: return None,[]
    inner=parse_fields(inner_payload)
    # header timestamp
    header_payload=submessage(inner,1)
    if header_payload is not None:
        h=parse_fields(header_payload)
        if 2 in h and h[2][0][0]==0: row['timestamp']=h[2][0][1]
    hr=[]
    metadata_payload=submessage(inner,2)
    if metadata_payload is not None:
        m=parse_fields(metadata_payload)
        iso_payload=submessage(m,3)
        if iso_payload is not None:
            iso=subfloats(iso_payload,(1,))[0]
            if iso is not None: row['iso']=iso
        color_payload=submessage(m,6)
        if color_payload is not None:
            color=parse_fields(color_payload).get(1,[])
            if color and color[0][0]==0: row['color_temp']=color[0][1]
        shutter_payload=submessage(m,4)
        if shutter_payload is not None:
            shutter=parse_fields(shutter_payload).get(1,[])
            if shutter and isinstance(shutter[0][1],bytes): row['shutter_raw']=shutter[0][1].hex()
        quat_payload=submessage(m,9)
        if quat_payload is not None:
            row['quat']=subfloats(quat_payload,(1,2,3,4))
        accel_payload=submessage(m,10)
        if accel_payload is not None:
            row['accel']=subfloats(accel_payload,(2,3,4))
        temp_payload=submessage(m,16)
        if temp_payload is not None:
            temp=subfloats(temp_payload,(1,))[0]
            if temp is not None: row['sensor_temp']=temp
    # high-rate quaternions under inner #3
    highrate_payload=submessage(inner,3)
    if highrate_payload is not None:
        b=highrate_payload
        lvl=parse_fields(b)          # #2
        lvl2_payload=submessage(lvl,2)
        if lvl2_payload is not None:
            b2=parse_fields(lvl2_payload)  # #1
            block_payload=submessage(b2,1)
            if block_payload is not None:
                blk=parse_fields(block_payload)
                ts=blk[1][0][1] if 1 in blk and blk[1][0][0]==0 else None
                for wt,v in blk.get(3,[]):
                    if wt==2:
                        q=subfloats(v,(1,2,3,4))
                        if all(x is not None for x in q): hr.append(q)
    return row,hr

def decode_calibration(first_sample):
    top=parse_fields(first_sample)
    out={'lenses':[]}
    if 1 in top:
        hdr=parse_fields(top[1][0][1])
        dev=parse_fields(hdr[1][0][1]) if 1 in hdr else {}
        def s(k):
            return dev[k][0][1].decode('utf-8','replace') if k in dev and dev[k][0][0]==2 else None
        out['proto']=s(1); out['fw_a']=s(2); out['proto_ver']=s(3)
        out['serial']=s(5); out['fw_b']=s(6); out['model']=s(10)
        if 9 in dev and dev[9][0][0]==0: out['boot_ts']=dev[9][0][1]
        if 3 in hdr:
            q=parse_fields(hdr[3][0][1])
            if 1 in q: out['initial_quat']=floats(q[1][0][1])
    def fixed(lp,k):
        entries=lp.get(k,[])
        if not entries or entries[0][0]!=5 or not isinstance(entries[0][1],bytes) or len(entries[0][1])!=4:
            return None
        return f32(entries[0][1])

    def lens_from(payload):
        lp=parse_fields(payload)
        # Both known schemas carry these six fixed32 intrinsics/dimensions.
        required=(1,2,3,4,5,6,7,8,10,11)
        if any(fixed(lp,k) is None for k in required):
            return None
        lens={
            'fx':fixed(lp,1),'fy':fixed(lp,2),'cx':fixed(lp,3),'cy':fixed(lp,4),
            'dist':[fixed(lp,k) for k in (5,6,7,8)],
            'width':fixed(lp,10),'height':fixed(lp,11),
            'yaw_deg':fixed(lp,12),'pitch_deg':fixed(lp,13),
        }
        # Osmo 360 proto 2.0.8 stores four packed floats at field 21.
        q21=lp.get(21,[])
        if q21 and q21[0][0]==2 and isinstance(q21[0][1],bytes) and len(q21[0][1])==16:
            quat=floats(q21[0][1])
            if all(math.isfinite(value) for value in quat) and sum(value*value for value in quat)>0:
                lens['extrinsic_quat']=quat
        # Avata360 proto 2.0.11 moved the quaternion to a nested message at 26.
        q26=lp.get(26,[])
        if 'extrinsic_quat' not in lens and q26 and q26[0][0]==2 and isinstance(q26[0][1],bytes):
            quat=subfloats(q26[0][1],(1,2,3,4))
            if all(value is not None for value in quat):
                lens['extrinsic_quat']=quat
        if 'extrinsic_quat' not in lens:
            return None
        numeric=[lens[key] for key in ('fx','fy','cx','cy','width','height')]
        numeric.extend(lens['dist'])
        numeric.extend(lens['extrinsic_quat'])
        if not all(math.isfinite(value) for value in numeric):
            return None
        if lens['width']<=0 or lens['height']<=0:
            return None
        if sum(value*value for value in lens['extrinsic_quat'])<=0:
            return None
        for field,name in ((22,'radial_lut_1'),(23,'radial_lut_2')):
            entries=lp.get(field,[])
            if entries and entries[0][0]==2 and isinstance(entries[0][1],bytes) and len(entries[0][1])%4==0:
                values=floats(entries[0][1])
                # Avata360 emits all-zero placeholders here; treating them as
                # image-space coordinates distorts the radial scale.
                if any(value!=0.0 for value in values):
                    lens[name]=values
        return lens

    def append_lenses(cal, fields=None):
        candidates=fields if fields is not None else sorted(cal)
        for field in candidates:
            for wt,payload in cal.get(field,[]):
                if wt!=2 or not isinstance(payload,bytes):
                    continue
                lens=lens_from(payload)
                if lens is not None:
                    out['lenses'].append(lens)

    if 2 in top:
        blk=parse_fields(top[2][0][1])
        # Osmo 360 (dvtm_oq101.proto 2.0.8): top.2.6, repeated lenses.
        if 6 in blk:
            append_lenses(parse_fields(blk[6][0][1]))
        # Avata360 (dvtm_AVATA360.proto 2.0.11): top.2.5, lenses 3/4.
        if not out['lenses'] and 5 in blk:
            append_lenses(parse_fields(blk[5][0][1]),(3,4))
    # Map generation is intrinsically dual-lens and indexes both entries.
    # Treat partial/ambiguous calibration as unavailable so the caller can
    # use its explicit ideal-geometry fallback rather than crash mid-stitch.
    if len(out['lenses'])<2:
        out['lenses']=[]
    else:
        out['lenses']=out['lenses'][:2]
    return out

def main():
    fn=sys.argv[1]
    outdir=sys.argv[2] if len(sys.argv)>2 else 'djmd_out'
    os.makedirs(outdir,exist_ok=True)
    data=open(fn,'rb').read()
    traks=get_traks(data)
    trak=find_djmd_full(traks)
    if not trak:
        print("no djmd track found");return
    samples=trak['samples']
    # calibration from first sample
    cal=decode_calibration(data[samples[0][0]:samples[0][0]+samples[0][1]])
    json.dump(cal,open(os.path.join(outdir,'calibration.json'),'w'),indent=2)
    # per-frame + high-rate from remaining samples
    pf_rows=[];hr_rows=[]
    for idx,(off,sz) in enumerate(samples):
        row,hr=decode_perframe(data[off:off+sz])
        if row is None: continue
        q=row.get('quat',[None]*4); a=row.get('accel',[None]*3)
        pf_rows.append([idx,row.get('timestamp'),*q,*a,
                        row.get('iso'),row.get('shutter_raw'),
                        row.get('color_temp'),row.get('sensor_temp')])
        for j,hq in enumerate(hr):
            hr_rows.append([idx,j,*hq])
    with open(os.path.join(outdir,'imu_perframe.csv'),'w',newline='') as fp:
        w=csv.writer(fp)
        w.writerow(['frame','timestamp','qw','qx','qy','qz','ax_g','ay_g','az_g','iso','shutter_raw','color_temp_K','sensor_temp_C'])
        w.writerows(pf_rows)
    with open(os.path.join(outdir,'imu_highrate.csv'),'w',newline='') as fp:
        w=csv.writer(fp)
        w.writerow(['frame','subidx','qw','qx','qy','qz'])
        w.writerows(hr_rows)
    print(f"frames={len(pf_rows)}  highrate_samples={len(hr_rows)}  (~{len(hr_rows)/max(1,len(pf_rows)):.1f}/frame)")
    print(f"lenses with data: {len(cal['lenses'])}")
    print("wrote:",outdir,"/ {calibration.json, imu_perframe.csv, imu_highrate.csv}")

if __name__=='__main__':
    main()
