import OMT from "@surma/rollup-plugin-off-main-thread";

export default {
	input: 'amd/src/library.js',
	output: {
		dir: "amd/build",
		format: 'amd'
	},
	plugins: [OMT()]
};
