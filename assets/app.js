// CSS is loaded via <link> tag in base.html.twig — not imported here to avoid
// a Windows path-separator bug in AssetMapper's JavaScriptImportPathCompiler.
import * as Turbo from '@hotwired/turbo';

Turbo.session.drive = true;
Turbo.setProgressBarDelay(800);

document.addEventListener('click', (event) => {
    const dismissButton = event.target.closest('.login-welcome-dismiss');
    dismissButton?.closest('.login-welcome-message')?.remove();
});

document.addEventListener('turbo:before-render', ({ detail }) => {
    const sidebar = document.getElementById('gei-sidebar');
    const nextSidebar = detail.newBody.querySelector('#gei-sidebar');
    if (!sidebar || !nextSidebar) return;

    sidebar.querySelectorAll('.sidebar-sublink, .sidebar-link[aria-controls]').forEach((item) => {
        const selector = item.matches('.sidebar-sublink')
            ? '.sidebar-sublink'
            : '.sidebar-link[aria-controls]';
        const nextItem = [...nextSidebar.querySelectorAll(selector)].find((candidate) => {
            return item.matches('.sidebar-sublink')
                ? candidate.getAttribute('href') === item.getAttribute('href')
                : candidate.getAttribute('aria-controls') === item.getAttribute('aria-controls');
        });

        item.classList.toggle('active', Boolean(nextItem?.classList.contains('active')));
    });

    sidebar.querySelectorAll('.sidebar-link[aria-controls]').forEach((item) => {
        if (!item.classList.contains('active')) return;

        const submenuId = item.getAttribute('aria-controls');
        const submenu = document.getElementById(submenuId);
        const arrow = item.querySelector('.sidebar-submenu-arrow');
        submenu?.classList.remove('hidden');
        arrow?.classList.add('rotated');
        item.setAttribute('aria-expanded', 'true');
    });
});

console.log('Plateforme GEI - Renseignement Opérationnel initialisé.');

const body = document.body;

const powerTrigger = document.getElementById('powerButton');
if (powerTrigger) {
    powerTrigger.addEventListener('click', () => {
        body.classList.add('power-on');
        const stage = document.querySelector('.power-stage');
        if (stage) {
            stage.style.opacity = '1';
        }
    });
}

const sidebarToggle = document.querySelector('.sidebar-toggle');
const appShell = document.querySelector('.app-shell');

if (sidebarToggle && appShell) {
    sidebarToggle.addEventListener('click', () => {
        body.classList.toggle('sidebar-open');
    });
}

const dropdown = document.getElementById('notifMenu');
const dropdownButton = document.querySelector('.notification-toggle');

if (dropdown && dropdownButton) {
    const closeDropdown = () => {
        dropdown.classList.add('hidden');
    };

    dropdownButton.addEventListener('click', (event) => {
        event.stopPropagation();
        dropdown.classList.toggle('hidden');
    });

    document.addEventListener('click', (event) => {
        if (!dropdown.contains(event.target) && !dropdownButton.contains(event.target)) {
            closeDropdown();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeDropdown();
        }
    });
}

const modal = document.getElementById('confirmModal');
const deleteForm = document.getElementById('deleteConfirmForm');
const deleteTokenInput = document.getElementById('deleteTokenInput');
const modalCloseButtons = document.querySelectorAll('.modal-close');

if (modal && deleteForm && deleteTokenInput) {
    const closeModal = () => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    };

    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-delete-url]');
        if (!button) return;

        event.preventDefault();
        const url = button.dataset.deleteUrl;
        if (!url) return;

        deleteForm.setAttribute('action', url);
        deleteTokenInput.value = button.dataset.deleteToken || '';
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    });

    modalCloseButtons.forEach((button) => {
        button.addEventListener('click', closeModal);
    });

    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeModal();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.classList.contains('hidden')) {
            closeModal();
        }
    });
}
