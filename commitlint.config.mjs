// Convención de commits del equipo: `tipo: descripción breve en presente`.
// Ver la página Desarrollo → Commits de la documentación. La revisa CI en cada PR (.github/workflows/commits.yml).
// Commits que ya estaban en `develop` antes de adoptar la revisión automática. No agregues nuevos.
const legacyCommits = [
  'adicion de migraciones',
  'fix: endurece la seguridad de la API (cabeceras, CORS, contraseñas, límites, logs y limpieza de EXIF)',
  'Initial plan',
];

export default {
  extends: ['@commitlint/config-conventional'],
  ignores: [(message) => legacyCommits.some((prefix) => message.startsWith(prefix))],
  rules: {
    'type-enum': [
      2,
      'always',
      ['feat', 'fix', 'docs', 'refactor', 'perf', 'test', 'chore', 'ci', 'build', 'style', 'revert'],
    ],
    // Las descripciones van en español: no se fuerza mayúscula/minúscula ni largo del cuerpo.
    'subject-case': [0],
    'body-max-line-length': [0],
    'footer-max-line-length': [0],
  },
};
