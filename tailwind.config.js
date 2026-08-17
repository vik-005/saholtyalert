/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './templates/**/*.{html,twig}',
    './assets/**/*.{js,jsx,ts,tsx}',
  ],
  darkMode: 'class',
  theme: {
    extend: {
      colors: {
        // Palette bleu nuit (identité principale)
        navy: {
          950: '#0B1220',
          900: '#0F1A2E',
          800: '#16233D',
          700: '#1F2F4F',
        },
        // Statuts réservés (jamais utilisés ailleurs)
        success: '#16A34A',   // validé / transmis / dans les délais
        danger: '#DC2626',    // critique / SLA dépassé
        warning: '#D97706',   // à compléter / attention
      },
      fontFamily: {
        sans: ['Inter', 'system-ui', 'sans-serif'],
      },
      spacing: {
        // Grille 4px/8px cohérente
        0.5: '0.125rem',   // 2px
        1: '0.25rem',      // 4px
        1.5: '0.375rem',   // 6px
        2: '0.5rem',       // 8px
        2.5: '0.625rem',   // 10px
        3: '0.75rem',      // 12px
        3.5: '0.875rem',   // 14px
        4: '1rem',         // 16px
      },
      boxShadow: {
        // Ombres subtiles uniquement (pas d'ombre lourde)
        sm: '0 1px 2px 0 rgba(0, 0, 0, 0.05)',
        md: '0 4px 6px -1px rgba(0, 0, 0, 0.1)',
        // Rien de plus agressif que md
      },
      borderRadius: {
        // Rayons cohérents
        sm: '0.375rem',    // 6px
        md: '0.5rem',      // 8px
        lg: '0.75rem',     // 12px
        xl: '1rem',        // 16px
      },
    },
  },
  plugins: [],
};
