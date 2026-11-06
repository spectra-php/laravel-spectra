// v-tooltip: the dashboard's tooltip, in place of the browser's `title`,
// which is slow to appear, unstyled and hard to read in dark mode.
//
//   <span v-tooltip="row.model">…</span>
//   <span class="truncate" v-tooltip.truncated="row.origin">…</span>   only when the text is cut off
//
// One tooltip element lives on <body>, so a card's overflow can't clip it.
// It opens above the element (below when there is no room), stays inside
// the window, wraps long text such as URLs, and shows on hover and on
// keyboard focus. An empty value shows nothing. The text is also set as the
// element's accessible description, as `title` was.
import type { ObjectDirective } from 'vue';

interface TooltipState {
    text: string;
    truncated: boolean;
    enter: () => void;
    leave: () => void;
}

type TooltipElement = HTMLElement & { _spectraTooltip?: TooltipState };

let tooltip: HTMLElement | null = null;
let owner: TooltipElement | null = null;

const element = () => {
    if (tooltip) return tooltip;

    tooltip = document.createElement('div');
    tooltip.setAttribute('role', 'tooltip');
    tooltip.className = 'pointer-events-none fixed z-60 max-w-md whitespace-pre-line wrap-break-word rounded-md bg-gray-900 px-2 py-1 text-[11px] font-medium leading-snug text-white shadow-pop dark:bg-gray-100 dark:text-gray-900';
    tooltip.style.display = 'none';
    document.body.appendChild(tooltip);

    return tooltip;
};

const isCutOff = (el: HTMLElement) => el.scrollWidth > el.clientWidth + 1 || el.scrollHeight > el.clientHeight + 1;

const hide = () => {
    if (tooltip) tooltip.style.display = 'none';
    owner = null;
    window.removeEventListener('scroll', hide, { capture: true });
};

const show = (el: TooltipElement) => {
    const state = el._spectraTooltip;

    if (!state?.text || (state.truncated && !isCutOff(el))) return;

    // A tooltip that only repeats text already shown in full adds nothing.
    if (!state.truncated && el.textContent!.trim() === state.text.trim() && !isCutOff(el)) return;

    const tip = element();
    tip.textContent = state.text;
    tip.style.display = 'block';
    tip.style.left = '0px';
    tip.style.top = '0px';

    const rect = el.getBoundingClientRect();
    const box = tip.getBoundingClientRect();
    const gap = 6;
    const margin = 8;

    const left = Math.min(Math.max(margin, rect.left), window.innerWidth - box.width - margin);
    const above = rect.top - box.height - gap;
    const top = above >= margin ? above : rect.bottom + gap;

    tip.style.left = `${left}px`;
    tip.style.top = `${top}px`;
    owner = el;
    window.addEventListener('scroll', hide, { passive: true, capture: true });
};

const describe = (el: HTMLElement, text: string) => {
    if (text) {
        el.setAttribute('aria-description', text);
    } else {
        el.removeAttribute('aria-description');
    }
};

const textOf = (value: unknown) => (value === null || value === undefined || value === false ? '' : String(value));

const directive: ObjectDirective<TooltipElement, unknown, 'truncated'> = {
    mounted(el, binding) {
        const state: TooltipState = {
            text: textOf(binding.value),
            truncated: !!binding.modifiers.truncated,
            enter: () => show(el),
            leave: () => owner === el && hide(),
        };
        el._spectraTooltip = state;
        describe(el, state.text);

        el.addEventListener('mouseenter', state.enter);
        el.addEventListener('mouseleave', state.leave);
        el.addEventListener('focusin', state.enter);
        el.addEventListener('focusout', state.leave);
        el.addEventListener('click', state.leave);
    },

    updated(el, binding) {
        const state = el._spectraTooltip as TooltipState;
        state.text = textOf(binding.value);
        describe(el, state.text);

        if (owner === el) {
            state.text ? show(el) : hide();
        }
    },

    beforeUnmount(el) {
        const state = el._spectraTooltip as TooltipState;

        if (owner === el) hide();

        el.removeEventListener('mouseenter', state.enter);
        el.removeEventListener('mouseleave', state.leave);
        el.removeEventListener('focusin', state.enter);
        el.removeEventListener('focusout', state.leave);
        el.removeEventListener('click', state.leave);
        delete el._spectraTooltip;
    },
};

export default directive;
