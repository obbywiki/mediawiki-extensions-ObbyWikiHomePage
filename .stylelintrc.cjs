module.exports = {
	extends: 'stylelint-config-recommended',
	rules: {
		// ResourceLoader serves this stylesheet directly.
		'at-rule-disallowed-list': [ 'import' ],
		'no-unknown-animations': true,
		// Keep the skin overrides and component states in their existing cascade order.
		'no-descending-specificity': null,
	},
};
