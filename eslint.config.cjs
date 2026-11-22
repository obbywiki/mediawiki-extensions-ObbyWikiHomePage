'use strict';

const js = require( '@eslint/js' );
const globals = require( 'globals' );

module.exports = [
	{
		files: [ 'resources/**/*.js' ],
		languageOptions: {
			ecmaVersion: 2019,
			sourceType: 'script',
			globals: globals.browser,
		},
		rules: {
			...js.configs.recommended.rules,
			camelcase: 'error',
			'no-var': 'error',
			'prefer-const': 'error',
		},
	},
];
