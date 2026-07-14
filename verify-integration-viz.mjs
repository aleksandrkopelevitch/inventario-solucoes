import { chromium } from 'playwright'

const BASE = 'http://127.0.0.1:8931'

const browser = await chromium.launch()
const page = await browser.newPage({ viewport: { width: 1400, height: 900 } })
page.on('console', (msg) => { if (msg.type() === 'error') console.log('CONSOLE ERROR:', msg.text()) })
page.on('pageerror', (err) => console.log('PAGE ERROR:', err.message))

async function shot(name) {
    await page.screenshot({ path: `/tmp/verify-${name}.png` })
    console.log('screenshot:', name)
}

// 1. Login
await page.goto(`${BASE}/login`)
await page.fill('input[name="email"]', 'verify-admin@example.com')
await page.fill('input[name="password"]', 'password123')
await page.click('button[type="submit"]')
await page.waitForLoadState('networkidle')
console.log('after login url:', page.url())

// 2. Go to solution detail page
await page.goto(`${BASE}/solucoes/svl-sistema-de-vendas-leo`)
await page.waitForSelector('#solution-integration-titles-slot')
await shot('01-solution-page')

// Sanity: no old modal button should exist
const oldBtn = await page.locator('[data-ak-modal-url*="integracoes/painel"]').count()
console.log('old "Gerenciar integrações" button count (expect 0):', oldBtn)

// 3. Create a new integration via inline form
await page.fill('#integration-create-form input[name="name"]', 'Verify Integração E2E')
await page.click('#integration-create-form button[data-ak-ajax]')
await page.waitForTimeout(700)
await shot('02-after-create')

const row = page.locator('[data-ak-integration-select]', { hasText: 'Verify Integração E2E' })
console.log('row count after create:', await row.count())
console.log('row aria-pressed (expect true, auto-selected):', await row.getAttribute('aria-pressed'))

const vizTitle = page.locator('[data-viz-title]')
console.log('viz title text:', await vizTitle.textContent())

// 4. Meta editor: pencil should be visible now, rename + change status
const metaBtn = page.locator('[data-viz-meta-edit]')
console.log('meta button visible:', await metaBtn.isVisible())
await metaBtn.click()
await page.waitForSelector('[data-viz-meta-editor]:not(.hidden)')
await shot('03-meta-editor-open')

await page.fill('[data-viz-meta-name]', 'Verify Renomeada')
await page.selectOption('[data-viz-meta-status]', 'active')
await page.click('[data-viz-meta-save]')
await page.waitForTimeout(700)
await shot('04-after-rename')

console.log('viz title after rename:', await vizTitle.textContent())
const renamedRow = page.locator('[data-ak-integration-select]', { hasText: 'Verify Renomeada' })
console.log('renamed row count:', await renamedRow.count())
console.log('status badge text:', await renamedRow.locator('[data-ak-integration-status]').textContent())

// 5. Add a block via canvas "+" button
const addBtn = page.locator('[data-viz-add-node]')
console.log('add-node button visible:', await addBtn.isVisible())
await addBtn.click()
await page.waitForSelector('[data-viz-add-editor]:not(.hidden)')
await page.selectOption('[data-viz-add-select]', 'free')
await page.fill('[data-viz-add-label]', 'Sistema Externo X')
await page.click('[data-viz-add-save]')
await page.waitForTimeout(700)
await shot('05-after-add-block')

const nodeCount = await page.locator('.ak-viz-node').count()
console.log('node count on canvas after add (expect 2):', nodeCount)

// 6. Probe: create without a name -> falls back to solution name
await page.fill('#integration-create-form input[name="name"]', '')
await page.click('#integration-create-form button[data-ak-ajax]')
await page.waitForTimeout(700)
await shot('06-create-no-name')
const fallbackRow = page.locator('[data-ak-integration-select]', { hasText: 'SVL' })
console.log('fallback-named row count (expect >=1):', await fallbackRow.count())

// 7. Probe: try meta save with an empty name -> should warn (client-side), not save
await renamedRow.click()
await page.waitForTimeout(300)
await metaBtn.click()
await page.waitForSelector('[data-viz-meta-editor]:not(.hidden)')
await page.fill('[data-viz-meta-name]', '')
await page.click('[data-viz-meta-save]')
await page.waitForTimeout(300)
await shot('07-empty-name-probe')
console.log('viz title unchanged after empty-name attempt:', await vizTitle.textContent())

await browser.close()
