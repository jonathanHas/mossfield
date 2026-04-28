import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', 'Figtree', ...defaultTheme.fontFamily.sans],
                mono: ['"JetBrains Mono"', ...defaultTheme.fontFamily.mono],
                display: ['Fraunces', ...defaultTheme.fontFamily.serif],
            },
            colors: {
                ink: {
                    DEFAULT: '#121311',
                    2: '#3A3D36',
                },
                bone: {
                    DEFAULT: '#FAF9F6',
                    panel: '#FFFFFF',
                    rail: '#F4F2EA',
                    app: '#EEECE4',
                },
                line: {
                    DEFAULT: '#E8E6DE',
                    2: '#F0EEE7',
                },
                muted: {
                    DEFAULT: '#6B6F65',
                    faint: '#A8ABA2',
                },
            },
        },
    },

    plugins: [forms],
};
