// Copies the plugin into dist/socialscore the way a release zip is laid out: everything except the .distignore entries.
import { cpSync, readFileSync, readdirSync, rmSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';

const root = new URL('..', import.meta.url).pathname;
const ignore = new Set(
	readFileSync(join(root, '.distignore'), 'utf8')
		.split('\n')
		.map((line) => line.trim())
		.filter(Boolean)
		.concat(['dist'])
);
const target = join(root, 'dist', 'socialscore');
rmSync(join(root, 'dist'), { recursive: true, force: true });
mkdirSync(target, { recursive: true });
for (const entry of readdirSync(root)) {
	if (!ignore.has(entry)) {
		cpSync(join(root, entry), join(target, entry), { recursive: true });
	}
}
console.log('dist/socialscore:', readdirSync(target).join(' '));
