#!/usr/bin/env node
/**
 * Pont CLI pour Laravel : lit JSON sur stdin, écrit FinalSearchResponse sur stdout.
 * @see App\Services\Vision\SerpApiOutfitSearchRunner
 */
import { searchOutfitByImage } from '@driply/serpapi-outfit-search';

const stdin = await new Promise((resolve, reject) => {
  let data = '';
  process.stdin.setEncoding('utf8');
  process.stdin.on('data', (chunk) => {
    data += chunk;
  });
  process.stdin.on('end', () => resolve(data));
  process.stdin.on('error', reject);
});

let input;
try {
  input = JSON.parse(stdin);
} catch (e) {
  console.error(JSON.stringify({ error: 'invalid_json_stdin', message: String(e) }));
  process.exit(1);
}

const imageUrl = typeof input.imageUrl === 'string' ? input.imageUrl.trim() : '';
if (!imageUrl) {
  console.error(JSON.stringify({ error: 'missing_imageUrl' }));
  process.exit(1);
}

const useMocks = Boolean(input.useMocks);
const serpApiApiKey = typeof input.serpApiApiKey === 'string' ? input.serpApiApiKey.trim() : '';
if (!serpApiApiKey && !useMocks) {
  console.error(JSON.stringify({ error: 'missing_serpApiApiKey' }));
  process.exit(1);
}

const cfg = {
  serpApiApiKey,
  language: typeof input.language === 'string' ? input.language : 'fr',
  country: typeof input.country === 'string' ? input.country : 'fr',
  googleDomain: typeof input.googleDomain === 'string' ? input.googleDomain : 'google.com',
  lensType: typeof input.lensType === 'string' ? input.lensType : 'visual_matches',
  timeoutMs: Number.isFinite(input.timeoutMs) ? input.timeoutMs : 25_000,
  maxRetries: Number.isFinite(input.maxRetries) ? input.maxRetries : 2,
  debug: Boolean(input.debug),
  useMocks,
  maxSerpApiCallsPerSearch: Number.isFinite(input.maxSerpApiCallsPerSearch)
    ? input.maxSerpApiCallsPerSearch
    : 28,
  targetTopProductsPerItem: Number.isFinite(input.targetTopProductsPerItem)
    ? input.targetTopProductsPerItem
    : 10,
};

try {
  const out = await searchOutfitByImage(
    { kind: 'url', publicImageUrl: imageUrl },
    { config: cfg }
  );
  process.stdout.write(JSON.stringify(out));
} catch (e) {
  const msg = e instanceof Error ? e.message : String(e);
  console.error(JSON.stringify({ error: 'pipeline_failed', message: msg }));
  process.exit(1);
}
