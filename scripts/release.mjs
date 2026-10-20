#!/usr/bin/env node
/**
 * Versionado a partir de los commits (convención `tipo: descripción`).
 *
 *   node scripts/release.mjs preview   → imprime en Markdown la versión que se publicará (comentario del PR a main)
 *   node scripts/release.mjs publish   → crea el tag y el GitHub Release (al entrar el PR a main)
 *
 * Toma los commits desde el último tag `vX.Y.Z` hasta HEAD (sin merges). Sin tags previos, la
 * primera versión es 0.1.0. La página /changelog de la documentación lee después estos GitHub Releases.
 * Es el mismo script de `docs-kampus` y de la app; si cambias uno, revisa los otros.
 */
import { execFileSync } from 'node:child_process';
import { appendFileSync, writeFileSync } from 'node:fs';

const FIRST_VERSION = '0.1.0';

// Mismas etiquetas que `sectionLabel` en src/lib/changelog.ts de docs-kampus: la página las usa para leer las notas.
const sections = [
  { type: 'breaking', label: 'Cambios importantes' },
  { type: 'features', label: 'Nuevas funciones' },
  { type: 'improvements', label: 'Mejoras' },
  { type: 'fixes', label: 'Correcciones' },
  { type: 'docs', label: 'Documentación' },
];

// Tipo de commit → sección del changelog e incremento de versión. Los demás (chore, ci, test, build,
// style, refactor) no publican versión. `docs` sí: el esquema de la base de datos y los diagramas de este
// repo son entregables del proyecto.
const commitTypes = {
  feat: { section: 'features', bump: 'minor' },
  fix: { section: 'fixes', bump: 'patch' },
  perf: { section: 'improvements', bump: 'patch' },
  revert: { section: 'fixes', bump: 'patch' },
  docs: { section: 'docs', bump: 'patch' },
};

const git = (...args) => execFileSync('git', args, { encoding: 'utf8' }).trim();

function lastTag() {
  return git('tag', '--list', 'v[0-9]*', '--sort=-v:refname').split('\n').find(Boolean);
}

function commitsSince(tag) {
  const range = tag ? [`${tag}..HEAD`] : ['HEAD'];
  const out = git('log', ...range, '--no-merges', '--format=%H%x1f%s%x1f%b%x1f%an%x1e');
  return out
    .split('\x1e')
    .map((raw) => raw.trim())
    .filter(Boolean)
    .map((raw) => {
      const [hash, subject, body, author] = raw.split('\x1f');
      const match = subject.match(/^(\w+)(?:\(([^)]*)\))?(!)?: (.+)$/);
      if (!match) return null;
      const [, type, scope, bang, text] = match;
      return { hash, type, scope, text, author, breaking: !!bang || /BREAKING[ -]CHANGE/.test(body ?? '') };
    })
    .filter(Boolean);
}

function nextVersion(current, commits) {
  const relevant = commits.filter((c) => c.breaking || commitTypes[c.type]);
  if (relevant.length === 0) return null;
  if (!current) return FIRST_VERSION;

  const [major, minor, patch] = current.replace(/^v/, '').split('.').map(Number);
  const breaking = relevant.some((c) => c.breaking);
  const feature = relevant.some((c) => commitTypes[c.type]?.bump === 'minor');

  // Antes de 1.0.0 un cambio importante sube el número de en medio, no el primero.
  if (breaking && major >= 1) return `${major + 1}.0.0`;
  if (breaking || feature) return `${major}.${minor + 1}.0`;
  return `${major}.${minor}.${patch + 1}`;
}

function repoUrl() {
  if (process.env.GITHUB_REPOSITORY) {
    return `${process.env.GITHUB_SERVER_URL ?? 'https://github.com'}/${process.env.GITHUB_REPOSITORY}`;
  }
  return git('remote', 'get-url', 'origin').replace(/\.git$/, '').replace(/^git@github\.com:/, 'https://github.com/');
}

function notes(commits) {
  const url = repoUrl();
  const bySection = new Map(sections.map((s) => [s.type, []]));
  for (const c of commits) {
    const line = `- ${c.scope ? `${c.scope}: ` : ''}${c.text} · ${c.author} ([${c.hash.slice(0, 7)}](${url}/commit/${c.hash}))`;
    const section = c.breaking ? 'breaking' : commitTypes[c.type]?.section;
    if (section) bySection.get(section).push(line);
  }
  return sections
    .filter((s) => bySection.get(s.type).length > 0)
    .map((s) => `### ${s.label}\n\n${bySection.get(s.type).join('\n')}`)
    .join('\n\n');
}

const command = process.argv[2];
const tag = lastTag();
const commits = commitsSince(tag);
const version = nextVersion(tag, commits);

if (command === 'preview') {
  if (!version) {
    console.log('**No se publicará una versión nueva**: solo los commits `feat`, `fix`, `perf`, `revert` y `docs` (o con `!`) publican versión.');
  } else {
    console.log(`Al mergear este PR se publicará **v${version}**${tag ? ` (antes ${tag})` : ' (primera versión)'}.\n\n${notes(commits)}`);
  }
} else if (command === 'publish') {
  if (!version) {
    console.log('No hay commits que publiquen versión desde', tag ?? 'el inicio');
  } else {
    writeFileSync('release-notes.md', notes(commits));
    execFileSync(
      'gh',
      ['release', 'create', `v${version}`, '--target', git('rev-parse', 'HEAD'), '--title', `v${version}`, '--notes-file', 'release-notes.md'],
      { stdio: 'inherit' },
    );
    console.log(`Publicada v${version}`);
  }
  if (process.env.GITHUB_OUTPUT) appendFileSync(process.env.GITHUB_OUTPUT, `version=${version ?? ''}\n`);
} else {
  console.error('Uso: node scripts/release.mjs <preview|publish>');
  process.exit(1);
}
