// @ts-ignore
import js from '@eslint/js';
// @ts-ignore
import globals from 'globals';
import tseslint from 'typescript-eslint';
// @ts-ignore
import importPlugin from 'eslint-plugin-import';
// @ts-ignore
import prettier from 'eslint-plugin-prettier';
import prettierConfig from 'eslint-config-prettier';

export default [
    js.configs.recommended,

    ...tseslint.configs.recommended,

    {
        ignores: ['eslint.config.ts'],
        files: ['**/*.ts', '**/*.tsx'],

        languageOptions: {
            parser: tseslint.parser,
            parserOptions: {
                ecmaVersion: 2020,
                sourceType: 'module',
            },
            globals: {
                ...globals.node,
                ...globals.browser,
                Atomics: 'readonly',
                SharedArrayBuffer: 'readonly',
            },
        },

        plugins: {
            '@typescript-eslint': tseslint.plugin,
            import: importPlugin,
            prettier: prettier,
        },

        settings: {
            'import/resolver': {
                typescript: {
                    alwaysTryTypes: true,
                },
                node: {
                    extensions: ['.js', '.jsx', '.ts', '.tsx'],
                },
            },
        },

        rules: {
            indent: ['error', 4],

            'no-multiple-empty-lines': ['error', { max: 1, maxEOF: 1, maxBOF: 0 }],

            quotes: ['error', 'single'],

            'import/newline-after-import': 'error',

            '@typescript-eslint/explicit-member-accessibility': [
                'error',
                { accessibility: 'explicit' },
            ],

            curly: ['error', 'all'],

            'import/order': [
                'error',
                {
                    pathGroups: [{ pattern: '@/**', group: 'internal' }],
                    pathGroupsExcludedImportTypes: ['builtin'],
                    groups: [
                        ['builtin', 'external'],
                        'internal',
                        ['parent', 'sibling', 'index'],
                    ],
                    'newlines-between': 'always',
                    alphabetize: {
                        order: 'asc',
                        caseInsensitive: true,
                    },
                },
            ],

            'prefer-const': 'error',

            // Parâmetros/variáveis prefixados com `_` são intencionalmente não
            // usados (ex.: o 4º arg do error-middleware do Express).
            '@typescript-eslint/no-unused-vars': [
                'error',
                {
                    argsIgnorePattern: '^_',
                    varsIgnorePattern: '^_',
                    caughtErrorsIgnorePattern: '^_',
                },
            ],

            eqeqeq: ['error', 'always'],

            '@typescript-eslint/no-explicit-any': 'error',

            'prettier/prettier': 'error',
        },
    },

    prettierConfig,
];
