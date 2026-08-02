/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',
    content: [
        './resources/**/*.blade.php',
        './resources/**/*.js',
        './resources/**/*.vue',
    ],
    theme: {
        extend: {
            colors: {
                ink: '#1c1c1e',
                brand: {
                    50: '#e9f5f8',
                    100: '#c8e6ec',
                    200: '#a1d3dd',
                    300: '#6fb9c8',
                    400: '#3d9fb0',
                    500: '#1f8ba0',
                    600: '#146c84',
                    700: '#0f5567',
                },
                gold: {
                    50: '#fdf8e7',
                    100: '#faedbd',
                    300: '#f6d668',
                    400: '#f5c531',
                    500: '#f0b400',
                    600: '#d19a00',
                },
                // shadcn/ui-style semantic tokens — driven by the HSL custom
                // properties in resources/css/app.css, so bg-background /
                // text-foreground / bg-card / border-border / bg-primary /
                // ring-ring all automatically flip for dark mode.
                background: 'hsl(var(--background) / <alpha-value>)',
                foreground: 'hsl(var(--foreground) / <alpha-value>)',
                card: 'hsl(var(--card) / <alpha-value>)',
                'card-foreground': 'hsl(var(--card-foreground) / <alpha-value>)',
                muted: 'hsl(var(--muted) / <alpha-value>)',
                'muted-foreground': 'hsl(var(--muted-foreground) / <alpha-value>)',
                border: 'hsl(var(--border) / <alpha-value>)',
                input: 'hsl(var(--input) / <alpha-value>)',
                primary: 'hsl(var(--primary) / <alpha-value>)',
                'primary-foreground': 'hsl(var(--primary-foreground) / <alpha-value>)',
                ring: 'hsl(var(--ring) / <alpha-value>)',
            },
            borderRadius: {
                DEFAULT: 'var(--radius)',
            },
            fontFamily: {
                sans: ['Inter', 'ui-sans-serif', 'system-ui'],
            },
        },
    },
    plugins: [],
};
