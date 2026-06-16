/**
 * npm install sonrası node_modules'dan assets/vendor'a kopyalar.
 * Kullanım: npm run install-assets
 */
const fs   = require('fs');
const path = require('path');

const root   = path.join(__dirname, '..');
const vendor = path.join(root, 'assets', 'vendor');

if (!fs.existsSync(vendor)) fs.mkdirSync(vendor, { recursive: true });

const copies = [
  ['node_modules/select2/dist/js/select2.min.js',  'assets/vendor/select2.min.js'],
  ['node_modules/select2/dist/css/select2.min.css', 'assets/vendor/select2.min.css'],
];

copies.forEach(([src, dest]) => {
  const from = path.join(root, src);
  const to   = path.join(root, dest);
  if (fs.existsSync(from)) {
    fs.copyFileSync(from, to);
    console.log('✅ Copied:', dest);
  } else {
    console.warn('⚠️  Not found:', src);
  }
});
