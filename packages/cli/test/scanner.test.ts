import assert from 'node:assert/strict';
import { mkdirSync, symlinkSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { test } from 'node:test';
import { keyFromClass, keyFromPath, toKey } from '../src/scan/keys.ts';
import { scanRepository } from '../src/scan/scanner.ts';
import { tempDir } from './helpers.ts';

function repo(files: Record<string, string>): string {
  const root = tempDir();
  for (const [path, content] of Object.entries(files)) {
    mkdirSync(dirname(join(root, path)), { recursive: true });
    writeFileSync(join(root, path), content);
  }
  return root;
}

const byKey = (root: string) => Object.fromEntries(scanRepository(root).candidates.map((c) => [c.key, c]));

test('key helpers normalise names and paths', () => {
  assert.equal(toKey('FeatureCategories'), 'feature-category');
  assert.equal(toKey('acceptance_criteria'), 'acceptance-criterion');
  assert.equal(toKey('statuses'), 'status');
  assert.equal(keyFromPath('api/v1/projects/{project}/features'), 'feature');
  assert.equal(keyFromPath('/projects/:id/archive'), 'project');
  assert.equal(keyFromPath('/forgot-password'), 'authentication');
  assert.equal(keyFromPath('/'), '');
  assert.equal(keyFromClass('ProjectDashboardController'), 'project-dashboard');
  assert.equal(keyFromClass('FeatureApiTest'), 'feature');
  assert.equal(keyFromClass('ProjectsIndex.php'), 'project');
});

test('detects Laravel routes, controllers, models, migrations, Livewire and tests', () => {
  const found = byKey(repo({
    'routes/web.php': "<?php\nRoute::get('/invoices', [InvoiceController::class, 'index']);\nRoute::livewire('/login', Login::class);",
    'routes/api.php': "<?php\nRoute::apiResource('projects.invoices', InvoiceController::class);",
    'app/Http/Controllers/InvoiceController.php': '<?php class InvoiceController {}',
    'app/Http/Controllers/Controller.php': '<?php abstract class Controller {}',
    'app/Models/Invoice.php': '<?php class Invoice {}',
    'database/migrations/2026_01_01_create_invoices_table.php': '<?php',
    'database/migrations/2026_01_01_create_cache_table.php': '<?php',
    'app/Livewire/InvoiceForm.php': '<?php',
    'tests/Feature/InvoiceApiTest.php': '<?php',
  }));

  const types = found.invoice!.evidence.map((e) => e.type).sort();
  assert.deepEqual(types, ['component', 'controller', 'migration', 'model', 'route', 'route', 'test']);
  assert.equal(found.invoice!.name, 'Invoice');
  assert.match(found.invoice!.description, /2 routes/);
  assert.ok(found.authentication, 'login route grouped as authentication');
  assert.equal(found.cache, undefined, 'framework tables ignored');
  assert.equal(found.controller, undefined, 'base controller ignored');

  const route = found.invoice!.evidence.find((e) => e.detail === 'GET /invoices')!;
  assert.equal(route.path, 'routes/web.php');
  assert.equal(route.line, 2);
});

test('detects JS routes, Next.js pages, Prisma models and README features', () => {
  const found = byKey(repo({
    'src/server.ts': "router.post('/api/orders', handler)",
    'app/orders/[id]/page.tsx': 'export default function Page() {}',
    'pages/_app.tsx': '',
    'prisma/schema.prisma': 'model Order {\n id Int @id\n}\nmodel Customer {\n id Int @id\n}',
    'README.md': '# Shop\n\n## Features\n\n- **Gift cards**: buy and redeem\n- Wishlists\n\n## Setup\n\n- Install deps',
  }));

  assert.deepEqual(found.order!.evidence.map((e) => e.type).sort(), ['model', 'page', 'route']);
  assert.ok(found.customer);
  assert.equal(found['gift-card']!.evidence[0]!.type, 'doc');
  assert.ok(found.wishlist);
  assert.equal(found['install-dep'], undefined, 'bullets outside the Features section are ignored');
  assert.equal(found.app, undefined);
});

test('skips dependency folders and does not follow symlinks', () => {
  const outside = repo({ 'app/Models/Secret.php': '<?php' });
  const root = repo({
    'vendor/laravel/Models/Framework.php': '<?php',
    'node_modules/pkg/routes.js': "app.get('/vendor-route', x)",
    'app/Models/Real.php': '<?php',
  });
  symlinkSync(join(outside, 'app'), join(root, 'linked'));

  const found = byKey(root);
  assert.ok(found.real);
  assert.equal(found.framework, undefined);
  assert.equal(found['vendor-route'], undefined);
  assert.equal(found.secret, undefined);
});

test('never includes file contents in the result', () => {
  const result = scanRepository(repo({
    'routes/web.php': "<?php\n// DB_PASSWORD=hunter2\nRoute::get('/reports', fn () => 'x');",
  }));
  assert.ok(!JSON.stringify(result).includes('hunter2'));
});

test('respects the file limit', () => {
  const root = repo(Object.fromEntries(Array.from({ length: 5 }, (_, i) => [`app/Models/M${i}.php`, '<?php'])));
  const result = scanRepository(root, { maxFiles: 2 });
  assert.equal(result.filesScanned, 2);
  assert.equal(result.truncated, true);
});
