// Scoped stylesheet, injected once. Every rule lives under .pe-root so the editor
// can be embedded in any host page without leaking styles in either direction.
export const CSS = `
.pe-root{--pe-accent:#2f7bf6;--pe-accent-ink:#fff;--pe-select:#2f7bf6;--pe-surface:#f6f6f8;--pe-bar:rgba(250,250,252,.92);--pe-raised:#fff;--pe-line:rgba(0,0,0,.09);--pe-ink:#1d1f24;--pe-muted:#6b707b;--pe-stage:#e4e5e9;--pe-hover:rgba(0,0,0,.06);--pe-pressed:rgba(0,0,0,.1);--pe-danger:#e5484d;--pe-shadow:0 10px 30px rgba(0,0,0,.18),0 1px 3px rgba(0,0,0,.12);
  position:relative;display:flex;flex-direction:column;width:100%;height:100%;min-height:360px;overflow:hidden;background:var(--pe-surface);color:var(--pe-ink);
  font:13px/1.35 -apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif;-webkit-font-smoothing:antialiased;user-select:none;box-sizing:border-box;outline:none;color-scheme:light}
.pe-root *,.pe-root *::before,.pe-root *::after{box-sizing:border-box}
.pe-root.pe-dark{color-scheme:dark;--pe-surface:#232428;--pe-bar:rgba(40,41,46,.94);--pe-raised:#34353b;--pe-line:rgba(255,255,255,.1);--pe-ink:#eeeff2;--pe-muted:#a3a7b1;--pe-stage:#18191c;--pe-hover:rgba(255,255,255,.08);--pe-pressed:rgba(255,255,255,.14);--pe-shadow:0 12px 34px rgba(0,0,0,.5),0 1px 3px rgba(0,0,0,.4)}
.pe-bar{display:flex;align-items:center;gap:6px;padding:6px 10px;background:var(--pe-bar);backdrop-filter:saturate(1.6) blur(14px);border-bottom:1px solid var(--pe-line);min-height:44px;flex-wrap:nowrap;overflow-x:auto;scrollbar-width:none}
.pe-bar::-webkit-scrollbar{display:none}
.pe-bottom{border-top:1px solid var(--pe-line);border-bottom:0;justify-content:space-between;min-height:40px;padding:5px 10px}
.pe-group{display:flex;align-items:center;gap:1px;padding:2px;border-radius:9px;background:var(--pe-hover)}
.pe-sep{width:1px;height:18px;background:var(--pe-line);margin:0 3px;flex:none}
.pe-spacer{flex:1 1 auto}
.pe-btn{appearance:none;border:0;background:transparent;color:inherit;height:28px;min-width:30px;padding:0 6px;border-radius:7px;display:inline-flex;align-items:center;justify-content:center;gap:4px;cursor:default;font:inherit;flex:none;transition:background .12s}
.pe-btn:hover{background:var(--pe-hover)}
.pe-btn:active{background:var(--pe-pressed)}
.pe-btn:focus-visible{outline:2px solid var(--pe-accent);outline-offset:1px}
.pe-btn[aria-pressed=true],.pe-btn.is-active{background:var(--pe-accent);color:var(--pe-accent-ink)}
.pe-btn[disabled]{opacity:.38;pointer-events:none}
.pe-btn.pe-chip{background:var(--pe-raised);box-shadow:0 0 0 1px var(--pe-line);padding:0 8px}
.pe-btn.pe-chip:hover{background:var(--pe-raised);box-shadow:0 0 0 1px var(--pe-line),inset 0 0 0 100px var(--pe-hover)}
.pe-text-btn{padding:0 12px;font-weight:500;background:var(--pe-raised);box-shadow:0 0 0 1px var(--pe-line)}
.pe-primary{background:var(--pe-accent)!important;color:var(--pe-accent-ink)!important;box-shadow:none}
.pe-primary:hover{filter:brightness(1.07)}
.pe-swatch{width:16px;height:16px;border-radius:50%;box-shadow:inset 0 0 0 1px rgba(0,0,0,.18);display:inline-block;flex:none}
.pe-ctx{display:flex;align-items:center;gap:6px}
.pe-ctx input[type=range]{width:96px}
.pe-root input[type=range]{accent-color:var(--pe-accent)}
.pe-body{position:relative;display:flex;flex:1 1 auto;min-height:0}
.pe-stage{position:relative;flex:1 1 auto;min-width:0;overflow:hidden;background:var(--pe-stage);touch-action:none;outline:none}
.pe-stage canvas{position:absolute;left:0;top:0;display:block}
.pe-stage.pe-checker{background-color:#3a3b40;background-image:linear-gradient(45deg,#4a4b50 25%,transparent 25%),linear-gradient(-45deg,#4a4b50 25%,transparent 25%),linear-gradient(45deg,transparent 75%,#4a4b50 75%),linear-gradient(-45deg,transparent 75%,#4a4b50 75%);background-size:16px 16px;background-position:0 0,0 8px,8px -8px,-8px 0}
.pe-textedit{position:absolute;left:0;top:0;transform-origin:0 0;margin:0;border:0;outline:none;resize:none;overflow:hidden;background:transparent;color:transparent;white-space:pre;padding:0;z-index:3;-webkit-user-select:text;user-select:text}
.pe-panel{width:236px;flex:none;border-right:1px solid var(--pe-line);background:var(--pe-surface);overflow-y:auto;scrollbar-width:thin;scrollbar-gutter:stable;padding:10px 8px 16px 10px;display:flex;flex-direction:column;gap:12px}
.pe-panel h4{margin:0 0 6px;font-size:11px;font-weight:600;color:var(--pe-muted);display:flex;justify-content:space-between;align-items:center}
.pe-panel h4 button{font-size:11px;color:var(--pe-muted);background:none;border:0;padding:0;cursor:default}
.pe-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:8px}
.pe-tile{appearance:none;border:0;padding:0;aspect-ratio:1;border-radius:9px;cursor:default;box-shadow:inset 0 0 0 1px rgba(0,0,0,.12);background-size:cover;background-position:center;position:relative}
.pe-tile[aria-pressed=true]{outline:2.5px solid var(--pe-accent);outline-offset:2px}
.pe-tile.pe-add{background:transparent;border:1.5px dashed var(--pe-muted);box-shadow:none;color:var(--pe-muted);display:flex;align-items:center;justify-content:center}
.pe-dots{display:grid;grid-template-columns:repeat(9,18px);gap:5px;justify-content:space-between}
.pe-dot{appearance:none;border:0;padding:0;width:18px;height:18px;border-radius:50%;box-shadow:inset 0 0 0 1px rgba(0,0,0,.18);cursor:default}
.pe-dot[aria-pressed=true]{outline:2px solid var(--pe-accent);outline-offset:2px}
.pe-dot.pe-transparent,.pe-swatch.pe-transparent{background:conic-gradient(#ccc 25%,#fff 0 50%,#ccc 0 75%,#fff 0) 0 0/8px 8px}
.pe-dot.pe-wheel{background:conic-gradient(red,yellow,lime,cyan,blue,magenta,red)}
.pe-row2{display:grid;grid-template-columns:1fr 1fr;gap:10px;align-items:end}
.pe-slider{display:flex;flex-direction:column;gap:3px}
.pe-slider input{width:100%}
.pe-field-label{font-size:11px;font-weight:600;color:var(--pe-muted)}
.pe-check-row{display:flex;align-items:center;gap:6px;font-size:12px}
.pe-align{display:grid;grid-template-columns:repeat(3,22px);gap:5px}
.pe-align button{appearance:none;width:22px;height:16px;border-radius:4px;border:1.5px solid var(--pe-muted);background:transparent;position:relative;cursor:default;padding:0}
.pe-align button::after{content:"";position:absolute;width:8px;height:6px;border-radius:1.5px;background:var(--pe-muted)}
.pe-align button[aria-pressed=true]{border-color:var(--pe-ink)}
.pe-align button[aria-pressed=true]::after{background:var(--pe-ink)}
.pe-align button[data-col="0"]::after{left:2px}.pe-align button[data-col="1"]::after{left:calc(50% - 4px)}.pe-align button[data-col="2"]::after{right:2px}
.pe-align button[data-row="0"]::after{top:2px}.pe-align button[data-row="1"]::after{top:calc(50% - 3px)}.pe-align button[data-row="2"]::after{bottom:2px}
.pe-tile.pe-none{font-weight:500}
.pe-select{appearance:none;height:26px;border-radius:7px;border:0;box-shadow:0 0 0 1px var(--pe-line);background:var(--pe-raised) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%23888' fill='none' stroke-width='1.5'/%3E%3C/svg%3E") no-repeat right 8px center;color:inherit;font:inherit;padding:0 24px 0 9px;width:100%}
.pe-input{height:26px;border-radius:6px;border:0;box-shadow:0 0 0 1px var(--pe-line);background:var(--pe-raised);color:inherit;font:inherit;padding:0 7px;width:70px;-webkit-user-select:text;user-select:text}
.pe-input:focus,.pe-select:focus{outline:2px solid var(--pe-accent);outline-offset:0}
.pe-popover{position:absolute;z-index:40;background:var(--pe-raised);color:var(--pe-ink);border-radius:11px;box-shadow:var(--pe-shadow);padding:6px;min-width:150px;border:1px solid var(--pe-line)}
.pe-menu{display:flex;flex-direction:column;min-width:170px}
.pe-menu-item{appearance:none;border:0;background:none;color:inherit;font:inherit;text-align:left;display:flex;align-items:center;gap:8px;padding:5px 8px;border-radius:6px;cursor:default}
.pe-menu-item:hover,.pe-menu-item:focus{background:var(--pe-accent);color:var(--pe-accent-ink);outline:none}
.pe-menu-item.danger{color:var(--pe-danger)}
.pe-menu-item.danger:hover{color:#fff;background:var(--pe-danger)}
.pe-menu-sep{height:1px;background:var(--pe-line);margin:4px 2px}
.pe-check{width:12px;font-size:12px}
.pe-menu-preview{display:inline-flex}
.pe-palette{display:flex;flex-direction:column;gap:8px;width:236px;padding:4px}
.pe-palette .pe-dots{grid-template-columns:repeat(8,1fr)}
.pe-palette-row{display:flex;align-items:center;gap:6px}
.pe-zoom{min-width:64px;justify-content:space-between}
.pe-drag{padding:0 14px;gap:8px;cursor:grab}
.pe-drag:active{cursor:grabbing}
.pe-toast{position:absolute;left:50%;bottom:56px;transform:translateX(-50%);background:rgba(20,21,24,.9);color:#fff;padding:7px 14px;border-radius:8px;z-index:50;pointer-events:none;font-weight:500;transition:opacity .2s}
.pe-modal-back{position:absolute;inset:0;background:rgba(0,0,0,.28);z-index:60;display:flex;align-items:center;justify-content:center}
.pe-modal{background:var(--pe-raised);border-radius:12px;box-shadow:var(--pe-shadow);padding:18px;width:min(380px,92%);display:flex;flex-direction:column;gap:12px}
.pe-modal h3{margin:0;font-size:15px}
.pe-modal p{margin:0;color:var(--pe-muted)}
.pe-modal .pe-actions{display:flex;justify-content:flex-end;gap:8px}
.pe-modal label{display:flex;flex-direction:column;gap:4px}
.pe-modal .pe-input{width:100%}
.pe-crop-hint{color:var(--pe-muted);font-size:11px}
.pe-size-label{color:var(--pe-muted);font-size:12px}
.pe-busy{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:var(--pe-muted);z-index:5}
@media (max-width:760px){.pe-panel{position:absolute;left:0;top:0;bottom:0;z-index:20;box-shadow:var(--pe-shadow)}}
`;

export function injectStyles(doc = document) {
    if (doc.getElementById("pe-styles")) return;
    const style = doc.createElement("style");
    style.id = "pe-styles";
    style.textContent = CSS;
    doc.head.append(style);
}
