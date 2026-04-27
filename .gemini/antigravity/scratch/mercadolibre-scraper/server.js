import express from 'express';
import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import axios from 'axios';
import { SITES, getGenericSelectors, parsePrice, STORES, getStoreSearchUrl } from './site-selectors.js';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const app = express();
const PORT = 3030;

app.use(express.json());
app.use(express.urlencoded({ extended: true }));
app.use(express.static('public'));
app.use('/search-projects', express.static(path.join(__dirname, 'search-projects')));

app.get('/api/sites', (req, res) => {
  const sitesList = Object.entries(SITES)
    .filter(([key]) => key !== 'custom')
    .map(([key, site]) => ({ key, name: site.name }));
  res.json(sitesList);
});

const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

const randomDelay = (min = 500, max = 2000) => {
  return new Promise(resolve => setTimeout(resolve, Math.random() * (max - min) + min));
};

const getSearchProjectsDir = () => {
  const dir = path.join(__dirname, 'search-projects');
  if (!fs.existsSync(dir)) {
    fs.mkdirSync(dir, { recursive: true });
  }
  return dir;
};

const getAllProjects = () => {
  const dir = getSearchProjectsDir();
  if (!fs.existsSync(dir)) return [];
  const folders = fs.readdirSync(dir).filter(f => {
    return fs.statSync(path.join(dir, f)).isDirectory();
  });
  return folders.sort().reverse(); // Más recientes primero
};

const getProjectInfo = (projectName) => {
  const projectDir = path.join(getSearchProjectsDir(), projectName);
  const jsonFile = path.join(projectDir, 'products.json');
  if (fs.existsSync(jsonFile)) {
    const data = JSON.parse(fs.readFileSync(jsonFile, 'utf8'));
    return {
      name: projectName,
      config: data.config,
      productsCount: data.products.length,
      hasImages: fs.existsSync(path.join(projectDir, 'images'))
    };
  }
  return null;
};

const buildSearchUrl = (query) => {
  return 'https://listado.mercadolibre.com.ar/' + encodeURIComponent(query);
};

const scrapeSite = async (siteKey, url, config) => {
  const isUrl = url.startsWith('http');
  
  let site;
  let finalUrl;
  
  if (isUrl) {
    site = { selectors: getGenericSelectors() };
    finalUrl = url;
  } else if (siteKey === 'custom') {
    site = { selectors: getGenericSelectors() };
    finalUrl = 'https://listado.mercadolibre.com.ar/' + encodeURIComponent(url);
  } else {
    site = SITES[siteKey] || SITES.mercadolibre;
    finalUrl = site?.searchUrl ? site.searchUrl(url) : 'https://listado.mercadolibre.com.ar/' + encodeURIComponent(url);
  }
  
  const selectors = site?.selectors || getGenericSelectors();
  
  const browser = await chromium.launch({
    headless: true,
    args: [
      '--disable-blink-features=AutomationControlled',
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--disable-dev-shm-usage',
      '--disable-accelerated-2d-canvas',
      '--disable-gpu',
      '--window-size=1920,1080'
    ]
  });

  const context = await browser.newContext({
    userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
    viewport: { width: 1920, height: 1080 },
    locale: 'es-AR',
    timezoneId: 'America/Argentina/Buenos_Aires',
    permissions: ['geolocation'],
    ignoreHTTPSErrors: true
  });

  const page = await context.newPage();
  
  await page.addInitScript(() => {
    Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
    window.navigator.chrome = { runtime: {} };
    Object.defineProperty(navigator, 'plugins', { get: () => [1, 2, 3, 4, 5] });
    Object.defineProperty(navigator, 'languages', { get: () => ['es-AR', 'es', 'en-US', 'en'] });
  });

  console.log('Buscando: ' + finalUrl);

  try {
    await page.goto(finalUrl, { waitUntil: 'networkidle', timeout: 30000 });
    await page.waitForTimeout(2000);
    await randomDelay(500, 1500);

    const itemCards = await page.evaluate(({sel, maxProds}) => {
      const cards = document.querySelectorAll(sel.container);
      const results = [];
      cards.forEach((card, i) => {
        if (i >= maxProds) return;
        
        try {
          const titleEl = card.querySelector(sel.title);
          const linkEl = card.querySelector(sel.titleLink);
          const imgEl = card.querySelector(sel.image);
          const descEl = card.querySelector(sel.description);
          
          let price = null;
          if (sel.price) {
            const priceEl = card.querySelector(sel.price);
            if (priceEl) {
              const priceText = priceEl.textContent || priceEl.innerText;
              const cleaned = priceText.replace(/[^0-9,.]/g, '').replace(/\./g, '').replace(/,/g, '.');
              price = parseFloat(cleaned) || null;
            }
          }
          
          let imageUrl = null;
          if (imgEl) {
            imageUrl = imgEl.src || imgEl.getAttribute('data-src') || imgEl.getAttribute('data-a-dynamic-image');
          }
          
          let url = '';
          if (linkEl && linkEl.href) {
            url = linkEl.href;
          } else if (sel.productLink) {
            const productLink = card.querySelector(sel.productLink);
            if (productLink) url = productLink.href || '';
          }
          
          let description = '';
          if (descEl) {
            description = descEl.textContent.trim().substring(0, 100);
          }
          
          if (titleEl) {
            results.push({ 
              title: titleEl.textContent.trim(), 
              price,
              imageUrl,
              url,
              description
            });
          }
        } catch (e) {}
      });
      return results;
    }, {sel: selectors, maxProds: config.maxProducts});
    
    await browser.close();
    return itemCards;

  } catch (error) {
    console.error('Error: ' + error.message);
    await browser.close();
    throw error;
  }
};

const downloadImage = async (url, filepath) => {
  try {
    const response = await axios({ url, method: 'GET', responseType: 'stream', timeout: 10000 });
    return new Promise((resolve, reject) => {
      response.data.pipe(fs.createWriteStream(filepath))
        .on('close', resolve)
        .on('error', reject);
    });
  } catch (err) {
    console.log('Error downloading image: ' + err.message);
    return false;
  }
};

const scrapeMercadoLibre = async (query, config) => {
  const browser = await chromium.launch({
    headless: true,
    args: ['--disable-blink-features=AutomationControlled', '--no-sandbox', '--disable-setuid-sandbox']
  });

  const context = await browser.newContext({
    userAgent: USER_AGENT,
    viewport: { width: 1920, height: 1080 },
    locale: 'es-AR'
  });

  const page = await context.newPage();
  
  await page.addInitScript(() => {
    Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
  });

  const searchUrl = buildSearchUrl(query);
  console.log('Buscando: ' + searchUrl);

  try {
    await page.goto(searchUrl, { waitUntil: 'networkidle', timeout: 30000 });
    await page.waitForTimeout(2000);
    await randomDelay(500, 1500);

    const itemCards = await page.evaluate(({maxProds}) => {
      const cards = document.querySelectorAll('.poly-card');
      const results = [];
      cards.forEach((card, i) => {
        if (i >= maxProds) return;
        
        const titleEl = card.querySelector('.poly-component__title');
        const linkEl = card.querySelector('a.poly-component__title');
        const imgEl = card.querySelector('img.poly-component__picture');
        const descEl = card.querySelector('.poly-component__description') || card.querySelector('.poly-item__description');
        
        let price = null;
        const mainPrice = card.querySelector('.andes-money-amount--main .andes-money-amount__fraction');
        if (mainPrice) {
          price = parseInt(mainPrice.textContent.replace(/\./g, '')) || null;
        }
        
        if (!price) {
          const normalPrice = card.querySelector('.andes-money-amount:not(.andes-money-amount--previous) .andes-money-amount__fraction');
          if (normalPrice) {
            price = parseInt(normalPrice.textContent.replace(/\./g, '')) || null;
          }
        }
        
        let imageUrl = null;
        if (imgEl) {
          imageUrl = imgEl.src || imgEl.getAttribute('data-src');
        }
        
        let url = '';
        if (linkEl && linkEl.href) {
          url = linkEl.href;
        } else {
          const anyLink = card.querySelector('a[href*="MLA"]');
          if (anyLink) {
            url = anyLink.href || '';
          }
        }
        
        let description = '';
        let fullDescription = '';
        if (descEl) {
          fullDescription = descEl.textContent.trim();
          description = fullDescription.substring(0, 100);
        } else if (imgEl && imgEl.alt) {
          fullDescription = imgEl.alt.trim();
          description = fullDescription.substring(0, 100);
        }
        
        if (titleEl) {
          results.push({ 
            title: titleEl.textContent.trim(), 
            price,
            imageUrl,
            url,
            description,
            fullDescription
          });
        }
      });
      return results;
    }, {maxProds: config.maxProducts});
    
    await browser.close();
    return itemCards;

  } catch (error) {
    console.error('Error: ' + error.message);
    await browser.close();
    throw error;
  }
};

const runScraping = async (req, res) => {
  try {
    const { site, query, url, maxProducts, includeImages, includeDescription, priceMin, priceMax, orderByPrice } = req.body;
    
    const maxProductsNum = parseInt(maxProducts) || 20;
    const priceMinNum = priceMin ? parseFloat(priceMin) : null;
    const priceMaxNum = priceMax ? parseFloat(priceMax) : null;
    const includeImagesBool = includeImages === 'on' || includeImages === true;
    const includeDescriptionBool = includeDescription === 'on' || includeDescription === true;
    const orderByPriceBool = orderByPrice === 'on' || orderByPrice === true;

    let baseUrl = url || 'https://listado.mercadolibre.com.ar/';
    if (!baseUrl.startsWith('http')) {
      baseUrl = 'https://listado.mercadolibre.com.ar/';
    }
    
    baseUrl = baseUrl.replace(/\/$/, '');
    const searchUrl = baseUrl + '/' + encodeURIComponent(query);
    
    console.log('Iniciando scraping: ' + query + ' en ' + baseUrl);

    const results = await scrapeSite('custom', searchUrl, { maxProducts: maxProductsNum });

    let filteredResults = results;

    if (priceMinNum !== null || priceMaxNum !== null) {
      filteredResults = results.filter(p => {
        if (!p.price) return false;
        if (priceMinNum && p.price < priceMinNum) return false;
        if (priceMaxNum && p.price > priceMaxNum) return false;
        return true;
      });
    }
    
    if (orderByPriceBool) {
      filteredResults.sort((a, b) => (a.price || 0) - (b.price || 0));
    }

    const now = new Date();
    const timestamp = '' + now.getFullYear() + 
      String(now.getMonth()+1).padStart(2,'0') + 
      String(now.getDate()).padStart(2,'0') + '_' + 
      String(now.getHours()).padStart(2,'0') + 
      String(now.getMinutes()).padStart(2,'0') + 
      String(now.getSeconds()).padStart(2,'0');
    const safeQuery = query.replace(/[^a-zA-Z0-9]/g, '_').substring(0, 20);
    const projectName = 'busqueda_custom_' + safeQuery + '_' + timestamp;
    const projectDir = path.join(getSearchProjectsDir(), projectName);
    
    fs.mkdirSync(projectDir, { recursive: true });
    const imagesDir = path.join(projectDir, 'images');
    if (includeImagesBool) {
      fs.mkdirSync(imagesDir, { recursive: true });
    }

    const config = {
      site: 'custom',
      baseUrl: baseUrl,
      query: query,
      maxProducts: maxProductsNum,
      includeImages: includeImagesBool,
      includeDescription: includeDescriptionBool,
      orderByPrice: orderByPriceBool,
      priceMin: priceMinNum,
      priceMax: priceMaxNum,
      timestamp: new Date().toISOString(),
      totalResults: filteredResults.length
    };

    const savedProducts = [];

    if (includeImagesBool) {
      console.log('Descargando imagenes...');
      for (let i = 0; i < filteredResults.length; i++) {
        const product = filteredResults[i];
        let localImagePath = null;
        
        if (product.imageUrl) {
          let ext = '.jpg';
          try { ext = path.extname(new URL(product.imageUrl).pathname) || '.jpg'; } catch {}
          const filename = 'product_' + String(i+1).padStart(3, '0') + ext;
          localImagePath = path.join('images', filename);
          const fullPath = path.join(projectDir, localImagePath);
          
          try {
            await downloadImage(product.imageUrl, fullPath);
            console.log('Imagen ' + (i+1) + ': ' + filename);
          } catch (err) {
            console.log('Error imagen ' + (i+1) + ': ' + err.message);
            localImagePath = null;
          }
          
          await randomDelay(200, 500);
        }
        
        savedProducts.push({
          title: product.title,
          price: product.price,
          image: localImagePath,
          url: product.url || '',
          imageUrl: product.imageUrl,
          description: product.description,
          fullDescription: includeDescriptionBool ? product.fullDescription : null
        });
      }
    } else {
      savedProducts.push(...filteredResults.map(p => ({
        title: p.title,
        price: p.price,
        image: null,
        url: p.url || '',
        imageUrl: p.imageUrl,
        description: p.description,
        fullDescription: includeDescriptionBool ? p.fullDescription : null
      })));
    }

    const output = {
      config,
      products: savedProducts
    };

    fs.writeFileSync(
      path.join(projectDir, 'products.json'),
      JSON.stringify(output, null, 2)
    );

    console.log('Scraping completado: ' + savedProducts.length + ' productos');

    res.json({
      success: true,
      projectName,
      projectDir,
      config,
      products: savedProducts
    });

  } catch (error) {
    console.error('Error en scraping:', error);
    res.status(500).json({ 
      success: false, 
      error: error.message 
    });
  }
};

app.get('/', (req, res) => {
  const html = '<!DOCTYPE html>' +
'<html lang="es">' +
'<head>' +
'<meta charset="UTF-8">' +
'<meta name="viewport" content="width=device-width, initial-scale=1.0">' +
'<title>E-commerce Scraper</title>' +
'<style>' +
'*{box-sizing:border-box;margin:0;padding:0}' +
'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:linear-gradient(135deg,#1e3a5f0%,#3d5a80 100%);min-height:100vh;padding:20px}' +
'.container{max-width:600px;margin:0 auto}' +
'.card{background:#fff;border-radius:16px;padding:32px;box-shadow:0 20px 60px rgba(0,0,0,0.3)}' +
'h1{color:#1e3a5f;margin-bottom:8px;font-size:28px}' +
'.subtitle{color:#666;margin-bottom:24px}' +
'.form-group{margin-bottom:20px}' +
'label{display:block;margin-bottom:8px;color:#333;font-weight:600}' +
'input[type="text"],input[type="number"],select{width:100%;padding:14px;border:2px solid #e0e0e0;border-radius:10px;font-size:16px;transition:border-color 0.3s}' +
'select{background:#fff;cursor:pointer}' +
'input:focus,select:focus{outline:none;border-color:#3d5a80}' +
'.price-inputs{display:flex;gap:12px;align-items:center}' +
'.price-inputs span{color:#666}' +
'.price-inputs input{flex:1}' +
'button{width:100%;padding:16px;background:linear-gradient(135deg,#3d5a80 0%,#1e3a5f 100%);color:#fff;border:none;border-radius:10px;font-size:18px;font-weight:600;cursor:pointer;transition:transform 0.2s,box-shadow 0.2s}' +
'button:hover{transform:translateY(-2px);box-shadow:0 10px 30px rgba(30,58,95,0.4)}' +
'button:disabled{opacity:0.7;cursor:not-allowed}' +
'#results{margin-top:24px}' +
'.result-card{background:#f8f9fa;border-radius:10px;padding:16px;margin-bottom:12px;border-left:4px solid #3d5a80}' +
'.result-card h3{color:#1e3a5f;font-size:16px;margin-bottom:8px}' +
'.result-card .price{color:#2ecc71;font-size:20px;font-weight:700}' +
'.result-card img{max-width:100px;border-radius:8px;margin-top:8px}' +
'.result-card .description{color:#666;font-size:12px;margin-top:4px}' +
'.loading{text-align:center;padding:40px;color:#666}' +
'.spinner{border:4px solid #f3f3f3;border-top:4px solid #3d5a80;border-radius:50%;width:40px;height:40px;animation:spin 1s linear infinite;margin:0 auto 16px}' +
'@keyframes spin{0%{transform:rotate(0deg)}100%{transform:rotate(360deg)}}' +
'.success-message{background:#d4edda;color:#155724;padding:16px;border-radius:10px;margin-bottom:16px}' +
'.error-message{background:#f8d7da;color:#721c24;padding:16px;border-radius:10px;margin-bottom:16px}' +
'.checkbox-group{display:flex;align-items:center;gap:10px;margin-top:8px}' +
'.checkbox-group input{width:20px;height:20px;cursor:pointer}' +
'.checkbox-group label{margin:0;font-weight:400}' +
'.modal-overlay{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:1000;justify-content:center;align-items:center}' +
'.modal-overlay.active{display:flex}' +
'.modal{background:#fff;border-radius:16px;padding:32px;width:90%;max-width:400px;text-align:center}' +
'.progress-bar{width:100%;height:8px;background:#e0e0e0;border-radius:4px;margin:20px 0;overflow:hidden}' +
'.progress-fill{height:100%;background:linear-gradient(90deg,#3d5a80,#2ecc71);width:0%;transition:width 0.3s}' +
'.json-link{display:inline-block;margin-top:16px;color:#3d5a80;text-decoration:none;font-weight:600}' +
'.json-link:hover{text-decoration:underline}' +
'</style>' +
'</head>' +
'<body>' +
'<div class="container">' +
'<div class="card">' +
'<h1>🛒 E-commerce Scraper</h1>' +
'<p class="subtitle">Configura tu busqueda de productos</p>' +
'<p style="margin-bottom:20px"><a href="/proyectos" style="color:#3d5a80;font-weight:600">📁 Ver proyectos guardados</a></p>' +
'<form id="scraperForm">' +
'<div class="form-group">' +
'<label>🔍 Producto a buscar</label>' +
'<input type="text" id="query" placeholder="Ej: Freidora de Aire" required>' +
'</div>' +
'<div class="form-group">' +
'<label>🌐 Tienda</label>' +
'<select id="storeSelect">' +
'<option value="all">⭐ Comparar todas</option>' +
'<option value="dropdeal">Dropdeal</option>' +
'<option value="unidrop">Unidrop</option>' +
'<option value="droppers">Droppers</option>' +
'<option value="mercadolibre">Mercado Libre</option>' +
'</select>' +
'</div>' +
'<div class="form-group">' +
'<label>📦 Resultados por tienda</label>' +
'<input type="number" id="maxProducts" value="10" min="1" max="50">' +
'</div>' +
'<div class="form-group">' +
'<label>💰 Filtro de precio (opcional)</label>' +
'<div class="price-inputs">' +
'<input type="number" id="priceMin" placeholder="Min $">' +
'<span>a</span>' +
'<input type="number" id="priceMax" placeholder="Max $">' +
'</div>' +
'</div>' +
'<div class="form-group">' +
'<label>🖼️ Opciones adicionales</label>' +
'<div class="checkbox-group">' +
'<input type="checkbox" id="includeImages">' +
'<label for="includeImages">Descargar imagenes</label>' +
'</div>' +
'<div class="checkbox-group">' +
'<input type="checkbox" id="includeDescription" checked>' +
'<label for="includeDescription">Incluir descripcion</label>' +
'</div>' +
'<div class="checkbox-group">' +
'<input type="checkbox" id="orderByPrice">' +
'<label for="orderByPrice">Ordenar por precio (menor a mayor)</label>' +
'</div>' +
'</div>' +
'<button type="submit" id="submitBtn">🚀 Iniciar Scraper</button>' +
'</form>' +
'<div id="results"></div>' +
'</div>' +
'</div>' +
'<div class="modal-overlay" id="modalOverlay">' +
'<div class="modal">' +
'<h2>⏳ Extrayendo datos...</h2>' +
'<div class="progress-bar">' +
'<div class="progress-fill" id="progressFill"></div>' +
'</div>' +
'<p id="progressText">0%</p>' +
'</div>' +
'</div>' +
'<script>' +
'const form = document.getElementById("scraperForm");' +
'const resultsDiv = document.getElementById("results");' +
'const submitBtn = document.getElementById("submitBtn");' +
'const modalOverlay = document.getElementById("modalOverlay");' +
'const progressFill = document.getElementById("progressFill");' +
'const progressText = document.getElementById("progressText");' +
'form.addEventListener("submit", async (e) => {' +
'e.preventDefault();' +
'const query = document.getElementById("query").value.trim();' +
'const store = document.getElementById("storeSelect") ? document.getElementById("storeSelect").value : "all";' +
'const selectedStores = store === "all" ? ["dropdeal", "unidrop", "droppers", "mercadolibre"] : [store];' +
'if (!query) { alert("Ingresa el producto a buscar"); return; }' +
'submitBtn.disabled = true;' +
'modalOverlay.classList.add("active");' +
'progressFill.style.width = "10%";' +
'progressText.textContent = "10% - Iniciando...";' +
'try {' +
'progressFill.style.width = "20%";' +
'progressText.textContent = "20% - Buscando en tiendas...";' +
'const response = await fetch("/api/scrape-multi", {' +
'method: "POST",' +
'headers: { "Content-Type": "application/json" },' +
'body: JSON.stringify({ stores: selectedStores, query, maxProducts })' +
'});' +
'progressFill.style.width = "80%";' +
'progressText.textContent = "80% - Procesando...";' +
'const data = await response.json();' +
'progressFill.style.width = "100%";' +
'progressText.textContent = "100% - Completado!";' +
'setTimeout(() => { modalOverlay.classList.remove("active"); progressFill.style.width = "0%"; }, 500);' +
'if (data.success) {' +
'let html = \'<div class="success-message">✅ Comparacion completada!</div>\';' +
'html += \'<h3>📋 Resultados por tienda:</h3>\';' +
'const storeNames = { dropdeal: "Dropdeal", unidrop: "Unidrop", droppers: "Droppers", mercadolibre: "Mercado Libre" };' +
'for (const [store, products] of Object.entries(data.results)) {' +
'html += \'<div class="result-card">\';' +
'html += \'<h3>🏪 \' + (storeNames[store] || store) + \' (\' + products.length + \' productos)</h3>\';' +
'products.slice(0, 5).forEach((p, i) => {' +
'html += \'<div style="margin-left:10px;margin-bottom:8px;border-left:2px solid #eee;padding-left:10px;">\';' +
'html += \'<strong>\' + (i+1) + ". " + (p.title || "Sin titulo").substring(0, 60) + \'</strong>\';' +
'html += \'<br><span class="price">$\' + (p.price ? p.price.toLocaleString("es-AR") : "N/A") + \'</span>\';' +
'if (p.url) { html += \' <a href="\' + p.url + \'" target="_blank" style="font-size:11px;">🔗</a>\'; }' +
'html += \'</div>\';});' +
'if (products.length > 5) { html += \'<p style="color:#666;font-size:12px;">...y \' + (products.length - 5) + \' mas</p>\'; }' +
'html += \'</div>\';}' +
'html += \'<br><a class="json-link" href="/search-projects/\' + data.projectName + \'/products.json" target="_blank">📄 Ver JSON completo</a>\';' +
'resultsDiv.innerHTML = html; } else {' +
'resultsDiv.innerHTML = \'<div class="error-message">❌ Error: \' + data.error + \'</div>\'; }' +
'} catch (err) {' +
'modalOverlay.classList.remove("active");' +
'resultsDiv.innerHTML = \'<div class="error-message">❌ Error: \' + err.message + \'</div>\'; }' +
'submitBtn.disabled = false; });' +
'</script></body></html>';
  
  res.send(html);
});

app.post('/api/scrape', runScraping);

app.post('/api/scrape-multi', async (req, res) => {
  try {
    const { stores, query, maxProducts } = req.body;
    const maxProductsNum = parseInt(maxProducts) || 10;
    
    const resultsByStore = {};
    const allStoreKeys = stores.includes('all') ? Object.keys(STORES) : stores;
    
    for (const storeKey of allStoreKeys) {
      const searchUrl = getStoreSearchUrl(storeKey, query);
      if (!searchUrl) continue;
      
      try {
        const results = await scrapeSite('custom', searchUrl, { maxProducts: maxProductsNum });
        resultsByStore[storeKey] = results;
        await randomDelay(1000, 2000);
      } catch (e) {
        console.log('Error en ' + storeKey + ': ' + e.message);
        resultsByStore[storeKey] = [];
      }
    }
    
    const now = new Date();
    const timestamp = '' + now.getFullYear() + 
      String(now.getMonth()+1).padStart(2,'0') + 
      String(now.getDate()).padStart(2,'0') + '_' + 
      String(now.getHours()).padStart(2,'0') + 
      String(now.getMinutes()).padStart(2,'0');
    const safeQuery = query.replace(/[^a-zA-Z0-9]/g, '_').substring(0, 15);
    const projectName = 'comparacion_' + safeQuery + '_' + timestamp;
    const projectDir = path.join(getSearchProjectsDir(), projectName);
    fs.mkdirSync(projectDir, { recursive: true });
    
    const output = {
      config: {
        query,
        stores: allStoreKeys,
        timestamp: new Date().toISOString()
      },
      results: resultsByStore
    };
    
    fs.writeFileSync(
      path.join(projectDir, 'products.json'),
      JSON.stringify(output, null, 2)
    );
    
    res.json({
      success: true,
      projectName,
      config: output.config,
      results: resultsByStore
    });
  } catch (error) {
    console.error('Error en scrape-multi:', error);
    res.status(500).json({ success: false, error: error.message });
  }
});

app.get('/proyectos', (req, res) => {
  const projects = getAllProjects();
  const projectList = projects.map(p => getProjectInfo(p)).filter(p => p !== null);
  
  let html = '<!DOCTYPE html>' +
'<html lang="es">' +
'<head>' +
'<meta charset="UTF-8">' +
'<meta name="viewport" content="width=device-width, initial-scale=1.0">' +
'<title>Proyectos Guardados</title>' +
'<style>' +
'*{box-sizing:border-box;margin:0;padding:0}' +
'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f5f5f5;padding:20px}' +
'.container{max-width:1000px;margin:0 auto}' +
'h1{color:#1e3a5f;margin:20px 0}' +
'.header-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}' +
'.back-btn{color:#3d5a80;text-decoration:none;font-weight:600;font-size:14px}' +
'.project-table{width:100%;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,0.1);margin-top:20px}' +
'.table-wrapper{overflow-x:auto}' +
'table{width:100%;border-collapse:collapse;min-width:700px}' +
'th,td{padding:12px 10px;text-align:left;border-bottom:1px solid #eee;font-size:13px}' +
'th{background:#3d5a80;color:#fff;white-space:nowrap}' +
'tr:hover{background:#f8f9fa}' +
'td.query{max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}' +
'td.name{font-size:12px;white-space:nowrap}' +
'.actions{display:flex;gap:8px;flex-wrap:wrap}' +
'.btn{background:#dc3545;color:#fff;border:none;padding:6px 12px;border-radius:5px;cursor:pointer;font-size:12px}' +
'.btn:hover{background:#c82333}' +
'.btn-view{background:#28a745;color:#fff;padding:6px 12px;border-radius:5px;text-decoration:none;font-size:12px;white-space:nowrap}' +
'.btn-view:hover{background:#218838}' +
'.badge{background:#28a745;color:#fff;padding:3px 8px;border-radius:4px;font-size:11px}' +
'.badge-no{background:#dc3545;color:#fff;padding:3px 8px;border-radius:4px;font-size:11px}' +
'</style>' +
'</head>' +
'<body>' +
'<div class="container">' +
'<div class="header-row">' +
'<h1>📁 Proyectos Guardados</h1>' +
'<a href="/" class="back-btn">← Volver al Scraper</a>' +
'</div>' +
'<div class="project-table">' +
'<div class="table-wrapper">' +
'<table>' +
'<thead><tr><th>Nombre</th><th>Sitio</th><th>Busqueda</th><th>Productos</th><th>Imagenes</th><th>Fecha</th><th>Acciones</th></tr></thead>' +
'<tbody>';
  
projectList.forEach(p => {
    const fecha = new Date(p.config.timestamp).toLocaleString('es-AR');
    const site = p.config.site || 'mercadolibre';
    const queryDisplay = p.config.query.length > 30 ? p.config.query.substring(0, 30) + '...' : p.config.query;
    html += '<tr>' +
    '<td class="name"><strong>' + p.name + '</strong></td>' +
    '<td>' + site + '</td>' +
    '<td class="query" title="' + p.config.query + '">' + queryDisplay + '</td>' +
    '<td>' + p.productsCount + '</td>' +
    '<td>' + (p.hasImages ? '<span class="badge">Si</span>' : '<span class="badge-no">No</span>') + '</td>' +
    '<td>' + fecha + '</td>' +
    '<td class="actions">' +
    '<a class="btn-view" href="/search-projects/' + encodeURIComponent(p.name) + '/products.json" target="_blank">JSON</a>' +
    '<a class="btn-share" href="https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(req.protocol + '://' + req.get('host') + '/share/' + encodeURIComponent(p.name) + '/0') + '" target="_blank" style="margin-left:8px;background:#3b5998;padding:6px 10px;border-radius:6px;color:#fff;text-decoration:none;font-size:12px">FB</a>' +
    '<a class="btn-share" href="https://api.whatsapp.com/send?text=' + encodeURIComponent(req.protocol + '://' + req.get('host') + '/share/' + encodeURIComponent(p.name) + '/0') + '" target="_blank" style="margin-left:6px;background:#25D366;padding:6px 10px;border-radius:6px;color:#fff;text-decoration:none;font-size:12px">WhatsApp</a>' +
    '<button class="btn" onclick="eliminar(\'' + p.name + '\')">Eliminar</button>' +
    '</td>' +
    '</tr>';
  });
  
  if (projectList.length === 0) {
    html += '<tr><td colspan="6" style="text-align:center;color:#666">No hay proyectos guardados</td></tr>';
  }
  
  html += '</tbody></table></div></div></div>' +
'<script>' +
'function eliminar(name) {' +
'if(confirm("Eliminar el proyecto " + name + "?")) {' +
'fetch("/api/proyectos/" + name, {method: "DELETE"})' +
'.then(r => r.json())' +
'.then(d => { if(d.success) location.reload(); else alert(d.error); })' +
'}' +
'}' +
'</script></body></html>';
  
  res.send(html);
});

app.delete('/api/proyectos/:name', (req, res) => {
  try {
    const projectName = req.params.name;
    const projectDir = path.join(getSearchProjectsDir(), projectName);
    
    if (!fs.existsSync(projectDir)) {
      return res.json({ success: false, error: 'Proyecto no encontrado' });
    }
    
    fs.rmSync(projectDir, { recursive: true, force: true });
    res.json({ success: true });
  } catch (err) {
    res.json({ success: false, error: err.message });
  }
});

// Helper: obtener producto desde products.json
const getProductFromProject = (projectName, index) => {
  try {
    const projectDir = path.join(getSearchProjectsDir(), projectName);
    const jsonFile = path.join(projectDir, 'products.json');
    if (!fs.existsSync(jsonFile)) return null;
    const data = JSON.parse(fs.readFileSync(jsonFile, 'utf8'));
    const products = data.products || data.results || [];
    // if results is an object (comparacion), try to flatten first store
    if (!Array.isArray(products) && typeof products === 'object') {
      // take first array found
      for (const k of Object.keys(products)) {
        if (Array.isArray(products[k])) {
          return products[k][index] || null;
        }
      }
      return null;
    }
    return products[index] || null;
  } catch (err) {
    return null;
  }
};

// Endpoint: servir imagen (decodifica base64 si es necesario)
app.get('/share-image/:project/:index', (req, res) => {
  const { project, index } = req.params;
  const idx = parseInt(index, 10);
  const product = getProductFromProject(project, idx);
  if (!product) return res.status(404).send('Producto no encontrado');

  // posibles campos: image (ruta local), imageBase64, image_b64, imageUrl
  if (product.image) {
    // si es una ruta relativa dentro del proyecto
    const projectDir = path.join(getSearchProjectsDir(), project);
    const imagePath = path.join(projectDir, product.image);
    if (fs.existsSync(imagePath)) {
      return res.sendFile(imagePath);
    }
    // si es URL externa
    try {
      const url = new URL(product.image);
      return res.redirect(product.image);
    } catch (e) {}
  }

  const b64keys = ['imageBase64','image_b64','image64','base64','imageData'];
  for (const k of b64keys) {
    if (product[k]) {
      try {
        const m = product[k].match(/^data:(image\/[a-zA-Z+]+);base64,(.*)$/);
        let mime = 'image/jpeg';
        let b64 = product[k];
        if (m) { mime = m[1]; b64 = m[2]; }
        const buffer = Buffer.from(b64, 'base64');
        res.set('Content-Type', mime);
        res.set('Cache-Control', 'public, max-age=86400');
        return res.send(buffer);
      } catch (err) {
        return res.status(500).send('Error decodificando imagen');
      }
    }
  }

  if (product.imageUrl) {
    return res.redirect(product.imageUrl);
  }

  return res.status(404).send('Imagen no disponible');
});

// Endpoint: página de share con Open Graph
app.get('/share/:project/:index', (req, res) => {
  const { project, index } = req.params;
  const idx = parseInt(index, 10);
  const product = getProductFromProject(project, idx);
  if (!product) return res.status(404).send('Producto no encontrado');

  const title = (product.title || product.name || 'Producto').replace(/</g,'').replace(/>/g,'');
  const desc = (product.description || product.fullDescription || '').replace(/</g,'').replace(/>/g,'').substring(0,200);
  const imageUrl = `${req.protocol}://${req.get('host')}/share-image/${encodeURIComponent(project)}/${idx}`;
  const pageUrl = `${req.protocol}://${req.get('host')}/search-projects/${encodeURIComponent(project)}/products.json`;

  const html = `<!doctype html><html><head>
  <meta charset="utf-8">
  <meta property="og:title" content="${title}">
  <meta property="og:description" content="${desc}">
  <meta property="og:image" content="${imageUrl}">
  <meta property="og:type" content="product">
  <meta property="og:url" content="${pageUrl}">
  <meta name="twitter:card" content="summary_large_image">
  <title>${title}</title>
  </head><body>
  <h1>${title}</h1>
  <p>${desc}</p>
  <img src="${imageUrl}" alt="image" style="max-width:100%">
  </body></html>`;

  res.send(html);
});

app.listen(PORT, () => {
  console.log(" Servidor iniciado en http://localhost:" + PORT);
});