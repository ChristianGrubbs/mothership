const A=30,M=90;function N({startFov:O,startDistance:_,distance:n}){return!(_>0)||!(n>0)?O:Math.max(30,Math.min(90,O*(_/n)))}export{M as PANO_FOV_MAX,A as PANO_FOV_MIN,N as pinchFov};
