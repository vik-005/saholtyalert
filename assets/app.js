import './styles/app.css';
import * as Turbo from '@hotwired/turbo';

// Désactiver Turbo Drive — il interceptait les redirects 302 (accès refusé → login)
// et les affichait inline, donnant l'impression d'une déconnexion.
// On garde Turbo pour les Streams (notifications Mercure) mais pas Drive.
Turbo.session.drive = false;

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
