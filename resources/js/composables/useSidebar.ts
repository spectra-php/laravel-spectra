import { ref } from 'vue';

// Whether the sidebar is collapsed to icons, shared by the sidebar and the
// layout and kept in this browser.
const read = () => {
    try {
        return localStorage.getItem('spectra-sidebar-collapsed') === 'true';
    } catch {
        return false;
    }
};

const collapsed = ref(read());

export function useSidebar() {
    const toggle = () => {
        collapsed.value = !collapsed.value;
        try {
            localStorage.setItem('spectra-sidebar-collapsed', String(collapsed.value));
        } catch {
            // Storage can be unavailable (private mode); the toggle still works for this page.
        }
    };

    return { collapsed, toggle };
}
