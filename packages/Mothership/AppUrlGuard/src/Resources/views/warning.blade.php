<div
    id="mothership-appurl-backdrop"
    class="mothership-appurl-backdrop"
    data-just-logged-in="{{ $justLoggedIn ? 'true' : 'false' }}"
    data-check-url="{{ $checkUrl }}"
    data-i18n-copy="@lang('app_url_guard::app.warning.copy')"
    data-i18n-copied="@lang('app_url_guard::app.warning.copied')"
    data-i18n-copied-toast="@lang('app_url_guard::app.warning.copied-toast')"
    data-i18n-still-mismatch="@lang('app_url_guard::app.warning.still-mismatch')"
    data-i18n-verify-failed="@lang('app_url_guard::app.warning.verify-failed')"
    data-i18n-progress="{{ trans('app_url_guard::app.warning.progress') }}"
>
    <div id="mothership-appurl-warning" class="mothership-appurl-modal" role="alertdialog" aria-modal="true" aria-labelledby="mothership-appurl-title">
        <div class="mothership-appurl-head">
            <span class="mothership-appurl-head-icon" aria-hidden="true">
                <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z" />
                    <path d="M12 9v4" />
                    <path d="M12 17h.01" />
                </svg>
            </span>

            <h1 class="mothership-appurl-head-title" id="mothership-appurl-title">@lang('app_url_guard::app.warning.title')</h1>

            <button type="button" class="mothership-appurl-head-close" aria-label="@lang('app_url_guard::app.warning.dismiss')">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M18 6 6 18" />
                    <path d="M6 6l12 12" />
                </svg>
            </button>
        </div>

        <div class="mothership-appurl-body">
            <p class="mothership-appurl-lede">
                @lang('app_url_guard::app.warning.lede-before')
                <b>APP_URL</b>.
                @lang('app_url_guard::app.warning.lede-after')
            </p>

            <div class="mothership-appurl-compare">
                <div class="mothership-appurl-cmp mothership-appurl-cmp--bad">
                    <div class="mothership-appurl-cmp-left">
                        <span class="mothership-appurl-cmp-label">@lang('app_url_guard::app.warning.configured-env')</span>
                        <span class="mothership-appurl-cmp-tag">@lang('app_url_guard::app.warning.mismatch-tag')</span>
                    </div>
                    <span class="mothership-appurl-cmp-url">{{ $configured }}</span>
                </div>

                <div class="mothership-appurl-cmp mothership-appurl-cmp--good">
                    <div class="mothership-appurl-cmp-left">
                        <span class="mothership-appurl-cmp-label">@lang('app_url_guard::app.warning.actual-browser')</span>
                        <span class="mothership-appurl-cmp-tag">@lang('app_url_guard::app.warning.in-use-tag')</span>
                    </div>
                    <span class="mothership-appurl-cmp-url">{{ $actual }}</span>
                </div>
            </div>

            <div class="mothership-appurl-steps">
                <div class="mothership-appurl-step" data-mothership-step="1">
                    <button type="button" class="mothership-appurl-step-badge" aria-label="@lang('app_url_guard::app.warning.toggle-step', ['number' => 1])">
                        <span class="mothership-appurl-step-n">1</span>
                        <svg class="mothership-appurl-step-tick" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5" /></svg>
                    </button>
                    <div class="mothership-appurl-step-main">
                        <div class="mothership-appurl-step-title">@lang('app_url_guard::app.warning.step-1-title')
                            <span class="mothership-appurl-step-hint">@lang('app_url_guard::app.warning.step-1-hint')</span>
                        </div>
                        <div class="mothership-appurl-code">
                            <code class="mothership-appurl-code-text"><span class="mothership-appurl-tok">APP_URL=</span>{{ $actual }}</code>
                            <button type="button" class="mothership-appurl-code-copy" data-copy="APP_URL={{ $actual }}">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" /><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" /></svg>
                                <span class="mothership-appurl-copy-label">@lang('app_url_guard::app.warning.copy')</span>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="mothership-appurl-step" data-mothership-step="2">
                    <button type="button" class="mothership-appurl-step-badge" aria-label="@lang('app_url_guard::app.warning.toggle-step', ['number' => 2])">
                        <span class="mothership-appurl-step-n">2</span>
                        <svg class="mothership-appurl-step-tick" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5" /></svg>
                    </button>
                    <div class="mothership-appurl-step-main">
                        <div class="mothership-appurl-step-title">@lang('app_url_guard::app.warning.step-2-title')
                            <span class="mothership-appurl-step-hint">@lang('app_url_guard::app.warning.step-2-hint')</span>
                        </div>
                        <div class="mothership-appurl-code">
                            <code class="mothership-appurl-code-text">php artisan optimize:clear</code>
                            <button type="button" class="mothership-appurl-code-copy" data-copy="php artisan optimize:clear">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" /><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" /></svg>
                                <span class="mothership-appurl-copy-label">@lang('app_url_guard::app.warning.copy')</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mothership-appurl-note">
                <span class="mothership-appurl-note-ico" aria-hidden="true">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10" /><path d="M12 8v4" /><path d="M12 16h.01" /></svg>
                </span>
                <div class="mothership-appurl-note-body">
                    <b>@lang('app_url_guard::app.warning.note-bold')</b> @lang('app_url_guard::app.warning.note-rest')
                    <span class="mothership-appurl-combo">
                        <span class="mothership-appurl-kbd">Ctrl</span><span class="mothership-appurl-plus">+</span><span class="mothership-appurl-kbd">Shift</span><span class="mothership-appurl-plus">+</span><span class="mothership-appurl-kbd">R</span>
                    </span>
                </div>
            </div>
        </div>

        <div class="mothership-appurl-foot">
            <div class="mothership-appurl-progress-wrap">
                <div class="mothership-appurl-progress-label" id="mothership-appurl-progress-label">@lang('app_url_guard::app.warning.progress', ['done' => 0, 'total' => 2])</div>
                <div class="mothership-appurl-progress-track"><div class="mothership-appurl-progress-fill" id="mothership-appurl-progress-fill"></div></div>
            </div>
            <button type="button" class="mothership-appurl-btn-done" id="mothership-appurl-btn-done" disabled>@lang('app_url_guard::app.warning.all-done')</button>
        </div>
    </div>

</div>

<div class="mothership-appurl-toast" id="mothership-appurl-toast" role="alert">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5" /></svg>
    <span id="mothership-appurl-toast-msg"></span>
</div>

@verbatim
<style>
    #mothership-appurl-backdrop {
        --brand-600: #6553EB;
        --brand-700: #5641D9;
        --brand-50:  #F0EDFC;
        --brand-100: #E3DEF9;
        --bg-card:   #FFFFFF;
        --bg-soft:   #FAF9FE;
        --bg-strip:  #F8F6FD;
        --ink-900: #1A1530;
        --ink-700: #3C3656;
        --ink-500: #6B6584;
        --ink-400: #8C87A3;
        --ink-300: #B6B2C7;
        --line:    #E8E4F2;
        --line-2:  #EFECF6;
        --danger-600: #EF4444;
        --danger-700: #DC2626;
        --danger-50:  #FEF2F2;
        --danger-100: #FEE2E2;
        --success-700: #15803D;
        --success-600: #16A34A;
        --success-50:  #F0FDF4;
        --success-100: #DCFCE7;
        --warn-600: #F59E0B;
        --warn-50:  #FFFBEB;
        --warn-100: #FEF3C7;
        --r-sm: 6px; --r-md: 10px; --r-lg: 14px; --r-xl: 18px;
        --shadow-lg: 0 24px 60px rgba(20, 16, 40, 0.30), 0 6px 20px rgba(20, 16, 40, 0.10);
        --uafont: "Manrope", -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif;
        --uamono: "JetBrains Mono", ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;

        display: none;
        position: fixed;
        inset: 0;
        z-index: 2147483647;
        padding: 18px;
        background: #F5F3FF;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 14px;
        overflow: auto;
        font-family: var(--uafont);
        color: var(--ink-900);
        -webkit-font-smoothing: antialiased;
    }

    html.dark #mothership-appurl-backdrop { background: #1F1C30; }

    #mothership-appurl-backdrop.is-open { display: flex; }
    #mothership-appurl-backdrop *,
    #mothership-appurl-toast * { box-sizing: border-box; }

    .mothership-appurl-modal {
        width: 430px;
        max-width: 100%;
        max-height: calc(100vh - 90px);
        display: flex;
        flex-direction: column;
        background: var(--bg-card);
        border-radius: var(--r-xl);
        box-shadow: var(--shadow-lg);
        overflow: hidden;
        animation: mothershipAppurlPop .2s cubic-bezier(.2,.7,.3,1);
    }
    @keyframes mothershipAppurlPop { from { opacity: 0; transform: translateY(8px) scale(.98); } to { opacity: 1; transform: none; } }

    .mothership-appurl-head {
        display: flex; align-items: center; gap: 10px;
        flex: 0 0 auto;
        padding: 14px 16px;
        border-bottom: 1px solid var(--line-2);
    }
    .mothership-appurl-head-icon {
        width: 30px; height: 30px; border-radius: 8px;
        background: var(--warn-50); border: 1px solid var(--warn-100);
        display: flex; align-items: center; justify-content: center;
        color: var(--warn-600); flex: 0 0 auto;
    }
    .mothership-appurl-head-title {
        font-size: 15px; font-weight: 800; letter-spacing: -0.015em;
        flex: 1; min-width: 0; margin: 0; color: var(--ink-900);
    }
    .mothership-appurl-head-close {
        width: 30px; height: 30px; border-radius: 8px;
        border: 1px solid var(--line); background: var(--bg-strip);
        color: var(--ink-500); cursor: pointer; flex: 0 0 auto;
        display: flex; align-items: center; justify-content: center;
        transition: background .12s, color .12s, border-color .12s;
    }
    .mothership-appurl-head-close:hover { background: var(--danger-50); color: var(--danger-600); border-color: var(--danger-100); }
    .mothership-appurl-head-close:disabled { opacity: .5; cursor: progress; }

    .mothership-appurl-body { padding: 16px 18px 4px; overflow: auto; }

    .mothership-appurl-lede { font-size: 13px; line-height: 1.55; color: var(--ink-500); margin: 0 0 14px; }
    .mothership-appurl-lede b { color: var(--ink-700); font-weight: 700; }

    .mothership-appurl-compare {
        display: flex; flex-direction: column; gap: 8px;
        margin-bottom: 16px; padding: 10px;
        background: var(--bg-strip); border: 1px solid var(--line-2); border-radius: var(--r-lg);
    }
    .mothership-appurl-cmp {
        background: #fff; border-radius: var(--r-md); padding: 10px 12px;
        border: 1px solid var(--line);
        display: flex; flex-direction: column; gap: 6px; min-width: 0;
    }
    .mothership-appurl-cmp-left { display: flex; align-items: center; gap: 8px; }
    .mothership-appurl-cmp-label { font-size: 10.5px; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--ink-500); }
    .mothership-appurl-cmp-tag {
        display: inline-flex; align-items: center; height: 18px; padding: 0 7px;
        border-radius: 5px; font-size: 9px; font-weight: 800; letter-spacing: 0.05em;
    }
    .mothership-appurl-cmp--bad .mothership-appurl-cmp-tag { background: var(--danger-50); color: var(--danger-700); border: 1px solid var(--danger-100); }
    .mothership-appurl-cmp--good .mothership-appurl-cmp-tag { background: var(--success-50); color: var(--success-700); border: 1px solid var(--success-100); }
    .mothership-appurl-cmp-url { font-family: var(--uamono); font-size: 12px; line-height: 1.5; color: var(--ink-900); word-break: break-all; }
    .mothership-appurl-cmp--bad .mothership-appurl-cmp-url { color: var(--ink-400); text-decoration: line-through; text-decoration-color: var(--danger-100); }
    .mothership-appurl-cmp--bad { border-left: 3px solid var(--danger-600); }
    .mothership-appurl-cmp--good { border-left: 3px solid var(--success-600); }

    .mothership-appurl-steps { display: flex; flex-direction: column; gap: 10px; }
    .mothership-appurl-step {
        display: grid; grid-template-columns: 28px 1fr; gap: 11px; align-items: start;
        padding: 11px 13px; border: 1px solid var(--line); border-radius: var(--r-md);
        background: var(--bg-soft); transition: border-color .14s, background .14s;
    }
    .mothership-appurl-step.is-done { background: var(--bg-strip); border-color: var(--line-2); }
    .mothership-appurl-step-badge {
        width: 28px; height: 28px; border-radius: 50%; border: none; cursor: pointer; flex: 0 0 auto;
        background: var(--brand-50); color: var(--brand-700);
        font-family: var(--uafont); font-size: 13px; font-weight: 800;
        display: flex; align-items: center; justify-content: center; position: relative;
        transition: background .14s, color .14s, transform .08s;
    }
    .mothership-appurl-step-badge:hover { transform: scale(1.06); }
    .mothership-appurl-step-n { transition: opacity .14s; }
    .mothership-appurl-step-tick { position: absolute; opacity: 0; transform: scale(.6); transition: opacity .14s, transform .14s; }
    .mothership-appurl-step.is-done .mothership-appurl-step-badge { background: var(--brand-600); color: #fff; }
    .mothership-appurl-step.is-done .mothership-appurl-step-n { opacity: 0; }
    .mothership-appurl-step.is-done .mothership-appurl-step-tick { opacity: 1; transform: none; }
    .mothership-appurl-step-main { min-width: 0; }
    .mothership-appurl-step-title {
        font-size: 13.5px; font-weight: 700; color: var(--ink-900);
        margin: 0 0 9px; letter-spacing: -0.01em; line-height: 1.35; padding-top: 3px; cursor: pointer;
    }
    .mothership-appurl-step.is-done .mothership-appurl-step-title { color: var(--ink-400); }
    .mothership-appurl-step-hint { display: block; font-size: 11.5px; font-weight: 500; color: var(--ink-400); margin-top: 3px; letter-spacing: 0; }

    .mothership-appurl-code {
        display: flex; align-items: stretch; gap: 0;
        border: 1px solid var(--line); border-radius: var(--r-md); background: var(--bg-strip); overflow: hidden;
    }
    .mothership-appurl-code-text {
        flex: 1; min-width: 0; font-family: var(--uamono); font-size: 12px; line-height: 1.5;
        color: var(--ink-900); padding: 9px 12px; white-space: nowrap; overflow-x: auto; overflow-y: hidden;
        display: flex; align-items: center; scrollbar-width: thin; scrollbar-color: var(--line) transparent;
    }
    .mothership-appurl-code-text::-webkit-scrollbar { height: 4px; }
    .mothership-appurl-code-text::-webkit-scrollbar-track { background: transparent; }
    .mothership-appurl-code-text::-webkit-scrollbar-thumb { background: var(--line); border-radius: 999px; }
    .mothership-appurl-tok { color: var(--brand-700); font-weight: 600; white-space: nowrap; }
    .mothership-appurl-code-copy {
        flex: 0 0 auto; align-self: stretch; display: inline-flex; align-items: center; gap: 6px;
        padding: 0 12px; border: none; border-left: 1px solid var(--line);
        background: #fff; color: var(--brand-700);
        font-family: var(--uafont); font-size: 12px; font-weight: 700; cursor: pointer;
        transition: background .12s, color .12s; white-space: nowrap;
    }
    .mothership-appurl-code-copy:hover { background: var(--brand-50); }
    .mothership-appurl-code-copy.is-copied { color: var(--success-600); }

    .mothership-appurl-note { display: flex; align-items: flex-start; gap: 9px; margin-top: 12px; padding: 0 2px; }
    .mothership-appurl-note-ico { display: inline-flex; color: var(--ink-400); flex: 0 0 auto; margin-top: 1px; }
    .mothership-appurl-note-body { font-size: 12.5px; line-height: 1.5; color: var(--ink-500); }
    .mothership-appurl-note-body b { color: var(--ink-700); font-weight: 700; }
    .mothership-appurl-combo { display: flex; align-items: center; gap: 6px; margin-top: 8px; }
    .mothership-appurl-kbd {
        display: inline-flex; align-items: center; justify-content: center; min-width: 27px; height: 25px;
        padding: 0 8px; background: #fff; border: 1px solid var(--line); border-bottom-width: 2px;
        border-radius: 6px; font-size: 11.5px; font-weight: 700; color: var(--ink-700); font-family: var(--uafont);
    }
    .mothership-appurl-plus { color: var(--ink-300); font-weight: 700; font-size: 12px; }

    .mothership-appurl-foot {
        display: flex; align-items: center; gap: 14px; flex: 0 0 auto;
        padding: 12px 18px; margin-top: 14px; border-top: 1px solid var(--line-2); background: var(--bg-strip);
    }
    .mothership-appurl-progress-wrap { flex: 1; min-width: 0; }
    .mothership-appurl-progress-label { font-size: 11px; font-weight: 700; color: var(--ink-500); margin-bottom: 5px; }
    .mothership-appurl-progress-track { height: 6px; border-radius: 999px; background: var(--brand-100); overflow: hidden; }
    .mothership-appurl-progress-fill { height: 100%; width: 0%; border-radius: 999px; background: var(--brand-600); transition: width .25s cubic-bezier(.2,.7,.3,1); }
    .mothership-appurl-btn-done {
        height: 36px; padding: 0 16px; border-radius: 9px; border: none;
        background: var(--brand-600); color: #fff; font-family: var(--uafont);
        font-size: 13px; font-weight: 700; cursor: pointer; transition: background .12s, opacity .12s; flex: 0 0 auto;
    }
    .mothership-appurl-btn-done:hover { background: var(--brand-700); }
    .mothership-appurl-btn-done:disabled { opacity: .45; cursor: not-allowed; }


    .mothership-appurl-toast {
        position: fixed; left: 50%; bottom: 28px; transform: translateX(-50%) translateY(20px);
        z-index: 2147483647; background: #1A1530; color: #fff;
        font-family: "Manrope", -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif;
        font-size: 13px; font-weight: 600; padding: 10px 16px; border-radius: 10px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.3); display: flex; align-items: center; gap: 8px;
        opacity: 0; pointer-events: none; transition: opacity .2s, transform .2s;
    }
    .mothership-appurl-toast.is-show { opacity: 1; transform: translateX(-50%) translateY(0); }
    .mothership-appurl-toast svg { color: #6EE7A8; flex: 0 0 auto; }
</style>

<script>
    (function () {
        var backdrop = document.getElementById('mothership-appurl-backdrop');
        if (! backdrop) return;

        // Translated strings injected by the Blade view (data-i18n-* attributes),
        // so the JS-built text honours the active Mothership locale too.
        var i18n = {
            copy:          backdrop.getAttribute('data-i18n-copy') || 'Copy',
            copied:        backdrop.getAttribute('data-i18n-copied') || 'Copied',
            copiedToast:   backdrop.getAttribute('data-i18n-copied-toast') || 'Copied to clipboard',
            stillMismatch: backdrop.getAttribute('data-i18n-still-mismatch') || 'APP_URL still does not match.',
            verifyFailed:  backdrop.getAttribute('data-i18n-verify-failed') || 'Could not verify APP_URL. Please refresh the page.',
            progress:      backdrop.getAttribute('data-i18n-progress') || ':done of :total steps complete'
        };

        var modal = document.getElementById('mothership-appurl-warning');
        var steps = Array.prototype.slice.call(modal.querySelectorAll('[data-mothership-step]'));
        var fill = document.getElementById('mothership-appurl-progress-fill');
        var label = document.getElementById('mothership-appurl-progress-label');
        var btnDone = document.getElementById('mothership-appurl-btn-done');
        var toast = document.getElementById('mothership-appurl-toast');
        var toastMsg = document.getElementById('mothership-appurl-toast-msg');
        var toastTimer;

        function showToast(msg) {
            toastMsg.textContent = msg;
            toast.classList.add('is-show');
            clearTimeout(toastTimer);
            toastTimer = setTimeout(function () { toast.classList.remove('is-show'); }, 2400);
        }

        function stepKey(step) {
            return 'mothership-appurl-step-' + step.getAttribute('data-mothership-step');
        }

        function updateProgress() {
            var done = steps.filter(function (s) { return s.classList.contains('is-done'); }).length;
            fill.style.width = (steps.length ? (done / steps.length * 100) : 0) + '%';
            label.textContent = i18n.progress
                .replace(':done', done)
                .replace(':total', steps.length);
            btnDone.disabled = done < steps.length;
        }

        function markStep(step, done) {
            step.classList.toggle('is-done', done);
            try { sessionStorage.setItem(stepKey(step), done ? 'true' : 'false'); } catch (e) {}
            updateProgress();
        }

        function fallbackCopy(text, apply) {
            var area = document.createElement('textarea');
            area.value = text;
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.focus();
            area.select();
            try { document.execCommand('copy'); } catch (e) {}
            document.body.removeChild(area);
            apply();
        }

        // Step toggling — badge OR title.
        steps.forEach(function (step) {
            var badge = step.querySelector('.mothership-appurl-step-badge');
            var title = step.querySelector('.mothership-appurl-step-title');
            function toggle() { markStep(step, ! step.classList.contains('is-done')); }
            if (badge) badge.addEventListener('click', toggle);
            if (title) title.addEventListener('click', toggle);
        });

        // Copy buttons — copy, tick the step, toast.
        modal.querySelectorAll('.mothership-appurl-code-copy').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var text = btn.getAttribute('data-copy') || '';
                var labelSpan = btn.querySelector('.mothership-appurl-copy-label');
                var apply = function () {
                    btn.classList.add('is-copied');
                    if (labelSpan) labelSpan.textContent = i18n.copied;
                    showToast(i18n.copiedToast);
                    setTimeout(function () {
                        btn.classList.remove('is-copied');
                        if (labelSpan) labelSpan.textContent = i18n.copy;
                    }, 1600);
                    var step = btn.closest('[data-mothership-step]');
                    if (step && ! step.classList.contains('is-done')) { markStep(step, true); }
                };
                if (navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext) {
                    navigator.clipboard.writeText(text).then(apply, function () { fallbackCopy(text, apply); });
                } else {
                    fallbackCopy(text, apply);
                }
            });
        });

        // Close (×) — re-validate APP_URL on the server before dismissing.
        var closeBtn = modal.querySelector('.mothership-appurl-head-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', function () {
                var url = backdrop.getAttribute('data-check-url');
                if (! url) { backdrop.classList.remove('is-open'); return; }

                closeBtn.disabled = true;
                fetch(url, { headers: { 'Accept': 'application/json' }, cache: 'no-store' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data && data.matches) {
                            window.location.reload();
                        } else {
                            closeBtn.disabled = false;
                            showToast(i18n.stillMismatch);
                        }
                    })
                    .catch(function () {
                        closeBtn.disabled = false;
                        showToast(i18n.verifyFailed);
                    });
            });
        }

        // "All done" — hard reload so the (hopefully fixed) assets are fetched.
        btnDone.addEventListener('click', function () {
            if (btnDone.disabled) return;
            window.location.reload();
        });

        // Restore / reset step state, then reveal.
        var justLoggedIn = backdrop.getAttribute('data-just-logged-in') === 'true';
        steps.forEach(function (step) {
            try {
                if (justLoggedIn) {
                    sessionStorage.removeItem(stepKey(step));
                } else if (sessionStorage.getItem(stepKey(step)) === 'true') {
                    step.classList.add('is-done');
                }
            } catch (e) {}
        });

        updateProgress();
        backdrop.classList.add('is-open');
    })();
</script>
@endverbatim
