export default {
  plugins: {
    // Boost / Bootstrap define .p-1..p-5, .m-*, .gap-* with !important, so
    // plain Tailwind utilities lose the cascade. Targeted overrides for the
    // colliding utilities live at the top of amd/src/styles.css instead.
    '@tailwindcss/postcss': {},
    autoprefixer: {},
  },
};
