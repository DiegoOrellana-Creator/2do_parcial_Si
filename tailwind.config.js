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
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                // Azul institucional de Clínica Santa Cruz (base: #071343)
                brand: {
                    50: '#eef2fb',
                    100: '#d9e1f5',
                    200: '#b6c4ea',
                    300: '#879dd9',
                    400: '#5672c2',
                    500: '#3651a6',
                    600: '#263d88',
                    700: '#1d2f6c',
                    800: '#142253',
                    900: '#0b1847',
                    950: '#071343',
                },
            },
        },
    },

    plugins: [forms],
};
