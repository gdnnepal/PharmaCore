/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './*.php',
    './modules/**/*.php',
  ],
  theme: {
    extend: {
      colors: {
        primary: '#0f766e',
        dark: '#1e293b',
      },
      fontFamily: {
        sans: ['Manrope', 'ui-sans-serif', 'system-ui', 'sans-serif'],
      },
    },
  },
  plugins: [],
};
