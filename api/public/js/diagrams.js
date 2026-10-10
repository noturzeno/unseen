/*
 * Renders Mermaid feature diagrams. Plain script, no build step.
 * Mermaid runs with securityLevel "strict": labels are sanitised and click handlers disabled,
 * because diagram text comes from users and agents.
 */
(function () {
    let seq = 0;
    let configured = false;

    function configure() {
        // The UI is light only (theme follows onezcodes.com), so diagrams are too.
        if (!window.mermaid || configured) return;
        configured = true;
        const ink = '#171614';
        window.mermaid.initialize({
            startOnLoad: false,
            securityLevel: 'strict',
            theme: 'base',
            fontFamily: 'Outfit, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif',
            flowchart: { curve: 'basis', padding: 12 },
            sequence: { mirrorActors: false, actorMargin: 60 },
            themeVariables: {
                background: '#ffffff', fontSize: '14px',
                primaryColor: '#fff4d6', primaryTextColor: ink, primaryBorderColor: '#e0a200',
                secondaryColor: '#f5f4f0', tertiaryColor: '#f5f4f0',
                lineColor: '#6b6862', textColor: ink, mainBkg: '#fff4d6', nodeBorder: '#e0a200',
                clusterBkg: '#fafaf8', clusterBorder: '#d6d3cc', edgeLabelBackground: '#ffffff',
                actorBkg: '#fff4d6', actorBorder: '#e0a200', actorTextColor: ink, actorLineColor: '#a8a49c',
                signalColor: '#3d3a35', signalTextColor: ink, noteBkgColor: '#fff4d6', noteTextColor: ink, noteBorderColor: '#e0a200',
                activationBkgColor: '#fff4d6', activationBorderColor: '#e0a200',
            },
        });
    }

    /*
     * In a sequence diagram, Mermaid ends message, note and block text at a ";" or "#" (they
     * separate statements and start comments), so "Check access; 422 if missing" fails to parse.
     * Agents write plain prose, so escape them as Mermaid entity codes (#59; and #35;), which
     * Mermaid turns back into the characters. Entity codes already in the text are kept.
     */
    const ENTITY = /^#\w+;$/;
    // Codes Mermaid's docs use for escaping (" # & ' ( ) : ; < >) and symbols from U+00A0 up.
    // Anything else ("ticket #12;") is prose, not an entity.
    const ESCAPE_CODES = [34, 35, 38, 39, 40, 41, 58, 59, 60, 62];

    function isEntity(part) {
        if (!ENTITY.test(part)) return false;
        const name = part.slice(1, -1);
        if (/^[a-z]+$/i.test(name)) return true;
        return /^\d+$/.test(name) && (ESCAPE_CODES.includes(Number(name)) || Number(name) >= 160);
    }
    const BLOCK = /^(\s*(?:loop|alt|else|opt|par|and|critical|option|break)\b)(.*)$/;
    const ALIAS = /^(\s*(?:participant|actor)\s+\S+\s+as\s)(.*)$/;

    function escapeText(text) {
        return text.split(/(#\w+;)/).map((part) => (isEntity(part) ? part : part.replace(/[#;]/g, (c) => (c === '#' ? '#35;' : '#59;'))))
            .join('');
    }

    function prepare(source) {
        if (!/^\s*sequenceDiagram\b/.test(source)) return source;
        return source.split('\n').map((line, i) => {
            if (i === 0 || /^\s*%%/.test(line)) return line;
            let m = ALIAS.exec(line) || BLOCK.exec(line);
            if (m) return m[1] + escapeText(m[2]);
            const colon = line.indexOf(':');
            if (colon === -1 || /^\s*(?:link|links|properties|details)\b/.test(line)) return line;
            return line.slice(0, colon + 1) + escapeText(line.slice(colon + 1));
        }).join('\n');
    }

    /** "Parse error on line 12:" plus the offending line, instead of the first line only. */
    function describe(error, source) {
        const message = String(error?.message ?? error);
        const first = message.split('\n')[0];
        const line = /line (\d+)/.exec(first);
        const text = line ? source.split('\n')[Number(line[1]) - 1] : null;
        return 'Could not draw this diagram: ' + first + (text ? '\n' + text.trim() : '')
            + '\nOpen "Source" to see it, and ask your agent to fix the diagram (Copy sync prompt).';
    }

    async function render(el, code) {
        if (!el) return;
        if (!window.mermaid) {
            el.textContent = 'Diagram renderer could not load (public/vendor/mermaid is missing).';
            el.classList.add('diagram-error');
            return;
        }
        configure();
        const source = (code ?? el.dataset.definition ?? '').trim();
        if (!source) {
            el.textContent = 'Nothing to draw yet.';
            return;
        }
        try {
            const { svg } = await window.mermaid.render('tf-diagram-' + (++seq), prepare(source));
            el.classList.remove('diagram-error');
            el.dataset.rendered = '1';
            el.innerHTML = svg; // produced by Mermaid in strict mode from sanitised input
        } catch (error) {
            el.classList.add('diagram-error');
            el.textContent = describe(error, source);
            // Mermaid leaves a temporary element behind on parse errors.
            document.getElementById('dtf-diagram-' + seq)?.remove();
        }
    }

    // Catch diagrams that were on the page before this script finished loading.
    function renderPending() {
        document.querySelectorAll('[data-diagram]:not([data-rendered])').forEach((el) => render(el));
    }
    document.addEventListener('DOMContentLoaded', renderPending);
    document.addEventListener('livewire:navigated', renderPending);

    /*
     * Alpine state for one diagram panel: full screen, then zoom (1 = fit the screen) and drag to pan.
     * Zoom keeps the point under the cursor (or the middle of the view) where it is.
     */
    const MIN_ZOOM = 0.5, MAX_ZOOM = 6, STEP = 1.25;

    function viewer() {
        return {
            full: false,
            zoom: 1,
            drag: null,

            canvas() { return this.$root.querySelector('[data-diagram]'); },

            enter() {
                this.zoom = 1;
                this.full = true;
                this.$root.requestFullscreen?.().catch(() => {});
                this.$nextTick(() => this.$refs.exitFull?.focus());
            },
            exit() {
                this.full = false;
                this.zoom = 1;
                if (document.fullscreenElement) document.exitFullscreen().catch(() => {});
            },

            setZoom(next, clientX, clientY) {
                const el = this.canvas();
                const old = this.zoom;
                next = Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, Math.round(next * 100) / 100));
                if (!el || next === old) return;
                const box = el.getBoundingClientRect();
                const ax = clientX === undefined ? el.clientWidth / 2 : clientX - box.left;
                const ay = clientY === undefined ? el.clientHeight / 2 : clientY - box.top;
                const x = (el.scrollLeft + ax) / old, y = (el.scrollTop + ay) / old;
                this.zoom = next;
                this.$nextTick(() => { el.scrollLeft = x * next - ax; el.scrollTop = y * next - ay; });
            },
            zoomIn() { this.setZoom(this.zoom * STEP); },
            zoomOut() { this.setZoom(this.zoom / STEP); },
            fit() { this.setZoom(1); },

            // Ctrl/Cmd + wheel, and trackpad pinch (which browsers send as ctrl + wheel).
            wheel(e) {
                if (!this.full || !(e.ctrlKey || e.metaKey)) return;
                e.preventDefault();
                this.setZoom(this.zoom * Math.exp(-e.deltaY * 0.01), e.clientX, e.clientY);
            },
            keys(e) {
                if (!this.full || e.target.closest?.('input, textarea')) return;
                if (e.key === '+' || e.key === '=') { e.preventDefault(); this.zoomIn(); }
                else if (e.key === '-' || e.key === '_') { e.preventDefault(); this.zoomOut(); }
                else if (e.key === '0') { e.preventDefault(); this.fit(); }
            },

            panStart(e) {
                const el = this.canvas();
                if (!this.full || !el?.contains(e.target) || e.button !== 0) return;
                this.drag = { x: e.clientX, y: e.clientY, left: el.scrollLeft, top: el.scrollTop };
                el.setPointerCapture?.(e.pointerId);
            },
            panMove(e) {
                if (!this.drag) return;
                const el = this.canvas();
                el.scrollLeft = this.drag.left - (e.clientX - this.drag.x);
                el.scrollTop = this.drag.top - (e.clientY - this.drag.y);
            },
            panEnd() { this.drag = null; },
        };
    }

    window.UnseenDiagrams = { render, viewer, prepare };
})();
