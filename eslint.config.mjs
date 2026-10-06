import js from '@eslint/js';
import globals from 'globals';
import tseslint from 'typescript-eslint';

export default tseslint.config(
  { ignores: ['node_modules', 'src/types/api.ts'] },
  js.configs.recommended,
  ...tseslint.configs.recommended,
  {
    files: ['src/**/*.ts'],
    languageOptions: { parserOptions: { projectService: true } },
  },
  {
    // The sync script runs under Node, not in the library's module graph. Without
    // these globals, `eslint src scripts` reports no-undef on `process` and `console`.
    files: ['scripts/**/*.mjs'],
    languageOptions: { globals: globals.node },
  },
);
