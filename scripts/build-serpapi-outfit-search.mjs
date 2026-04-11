/**
 * Optionnel : uniquement si `VISION_SCAN_DRIVER=serpapi` et le paquet est présent dans node_modules.
 * Lancer depuis Driply-api : `npm run build:serpapi-outfit` (nécessite Node + dépendance @driply/serpapi-outfit-search).
 */
import { existsSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { execSync } from 'node:child_process';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const pkg = join(root, 'node_modules', '@driply', 'serpapi-outfit-search');

if (!existsSync(pkg)) {
  process.stderr.write(
    '[driply-api] @driply/serpapi-outfit-search absent — normal en mode legacy (sans Node). Pour le driver serpapi, liez le paquet puis `npm run build:serpapi-outfit`.\n'
  );
  process.exit(0);
}

const distMain = join(pkg, 'dist', 'index.js');
if (existsSync(distMain)) {
  process.exit(0);
}

process.stderr.write('[driply-api] Build de @driply/serpapi-outfit-search…\n');
execSync('npm run build', { cwd: pkg, stdio: 'inherit' });
