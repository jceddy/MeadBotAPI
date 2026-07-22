'use strict';

// TOOLS - declarative config for the "Calculators" tab: one entry per REST endpoint, driving a
// generic form-and-result renderer below. Kept separate from Chat\Tools' LLM-facing schemas
// (src/Chat/Tools.php) on purpose -- these are for a human filling out a form, not a model
// choosing arguments, so field lists/labels/grouping differ (e.g. the three big "build a recipe"
// endpoints only surface their most commonly-used fields here; the rest are documented at /docs).
const VOLUME_UNITS = [
  'liters',
  'gallons_us',
  'gallons_imp',
  'fl_ounces_us',
  'fl_ounces_imp',
  'pints_us',
  'pints_imp',
  'quarts_us',
  'quarts_imp',
  'cups_us',
  'cups_imp',
  'cups_metric',
];
const HONEY_UNITS = [
  'kilograms',
  'pounds',
  'liters',
  'gallons_us',
  'gallons_imp',
  'ounces',
  'cups_us',
  'cups_imp',
  'cups_metric',
  'fl_ounces_us',
  'fl_ounces_imp',
  'pints_us',
  'pints_imp',
  'quarts_us',
  'quarts_imp',
];

function numberField(name, label, opts) {
  return Object.assign({ name, label, type: 'number', step: 'any' }, opts || {});
}
function textField(name, label, opts) {
  return Object.assign({ name, label, type: 'text' }, opts || {});
}
function selectField(name, label, options, opts) {
  return Object.assign({ name, label, type: 'select', options }, opts || {});
}
function checkboxField(name, label, opts) {
  return Object.assign({ name, label, type: 'checkbox' }, opts || {});
}

const TOOLS = [
  {
    group: 'ABV & Calories',
    id: 'abv',
    title: 'ABV from Gravity',
    method: 'POST',
    path: '/api/v1/abv',
    description: 'Estimate %ABV from an original and final gravity. Omit FG to estimate a "dry" FG.',
    fields: [numberField('og', 'Original Gravity', { required: true, placeholder: '1.100' }), numberField('fg', 'Final Gravity (optional)', { placeholder: '1.000' })],
  },
  {
    group: 'ABV & Calories',
    id: 'gravity-drop-to-abv',
    title: 'Gravity Drop to ABV',
    method: 'POST',
    path: '/api/v1/gravity-drop-to-abv',
    description: 'Convert a gravity drop (OG - FG + 1) to an estimated %ABV.',
    fields: [numberField('sgDelta', 'Gravity Drop', { required: true, placeholder: '1.100' })],
  },
  {
    group: 'ABV & Calories',
    id: 'dry-fg',
    title: 'Estimate Dry FG',
    method: 'POST',
    path: '/api/v1/dry-fg',
    description: 'Estimate the final gravity if a batch ferments fully dry.',
    fields: [numberField('og', 'Original Gravity', { required: true, placeholder: '1.100' })],
  },
  {
    group: 'ABV & Calories',
    id: 'calories',
    title: 'Calories',
    method: 'POST',
    path: '/api/v1/calories',
    description: 'Estimate calories per bottle and per serving.',
    fields: [
      numberField('percentAlcohol', '% ABV', { required: true, placeholder: '12' }),
      numberField('fg', 'Final Gravity', { required: true, placeholder: '1.000' }),
      numberField('bottleVolume', 'Bottle Volume (mL)', { required: true, placeholder: '750' }),
      numberField('servingVolume', 'Serving Volume (mL)', { required: true, placeholder: '150' }),
    ],
  },
  {
    group: 'Gravity',
    id: 'sg-to-brix',
    title: 'SG to Brix',
    method: 'POST',
    path: '/api/v1/sg-to-brix',
    description: 'Convert a specific gravity to degrees Brix.',
    fields: [numberField('sg', 'Specific Gravity', { required: true, placeholder: '1.100' })],
  },
  {
    group: 'Gravity',
    id: 'delle',
    title: 'Delle Number',
    method: 'POST',
    path: '/api/v1/delle',
    description: 'Estimate Delle units (a stability heuristic) from %ABV and specific gravity.',
    fields: [numberField('abv', '% ABV', { required: true, placeholder: '13' }), numberField('sg', 'Specific Gravity', { required: true, placeholder: '1.010' })],
  },
  {
    group: 'Gravity',
    id: 'potential-alcohol',
    title: 'Potential Alcohol Solver',
    method: 'POST',
    path: '/api/v1/potential-alcohol',
    description: 'Give any two of OG/FG/ABV (leave the third blank) and solve for the rest.',
    fields: [
      selectField('gravityUnits', 'Gravity Units', ['sg', 'brix', 'baume'], { default: 'sg' }),
      selectField('abvUnits', 'ABV Units', ['abv', 'abw'], { default: 'abv' }),
      numberField('og', 'Original Gravity'),
      numberField('fg', 'Final Gravity'),
      numberField('abv', '% ABV'),
    ],
  },
  {
    group: 'Units',
    id: 'volume-convert',
    title: 'Convert Volume',
    method: 'POST',
    path: '/api/v1/volume/convert',
    fields: [
      numberField('amount', 'Amount', { required: true, placeholder: '5' }),
      selectField('fromUnit', 'From', VOLUME_UNITS, { default: 'gallons_us' }),
      selectField('toUnit', 'To', VOLUME_UNITS, { default: 'liters' }),
    ],
  },
  {
    group: 'Units',
    id: 'honey-convert',
    title: 'Convert Honey',
    method: 'POST',
    path: '/api/v1/honey/convert',
    fields: [
      numberField('amount', 'Amount', { required: true, placeholder: '3' }),
      selectField('fromUnit', 'From', HONEY_UNITS, { default: 'pounds' }),
      selectField('toUnit', 'To', HONEY_UNITS, { default: 'kilograms' }),
    ],
  },
  {
    group: 'Units',
    id: 'temperature-convert',
    title: 'Convert Temperature',
    method: 'POST',
    path: '/api/v1/temperature/convert',
    fields: [numberField('fromTemperature', 'Value', { required: true, placeholder: '68' }), selectField('fromUnit', 'From', ['fahrenheit', 'celsius'], { default: 'fahrenheit' })],
  },
  {
    group: 'Recipe Building',
    id: 'calculate-blend',
    title: 'Blend Two Liquids',
    method: 'POST',
    path: '/api/v1/calculate-blend',
    description: 'Solve for one field of a two-liquid blend given the others. value1 must be <= value2.',
    fields: [
      selectField('fieldToCalculate', 'Solve For', ['value1', 'value2', 'blended_value', 'volume1', 'volume2', 'total_volume'], { default: 'blended_value' }),
      numberField('value1', "Liquid 1's Value"),
      numberField('value2', "Liquid 2's Value"),
      numberField('blendedValue', 'Blended Value'),
      numberField('volume1', 'Volume 1'),
      numberField('volume2', 'Volume 2'),
      numberField('totalVolume', 'Total Volume'),
    ],
  },
  {
    group: 'Recipe Building',
    id: 'calculate-nutrients',
    title: 'Nutrient (SNA) Schedule',
    method: 'POST',
    path: '/api/v1/calculate-nutrients',
    description: 'Core fields shown -- see /docs for every advanced override (limits, ratios, custom YAN values).',
    fields: [
      selectField('units', 'Units', ['us', 'metric'], { default: 'us' }),
      numberField('volume', 'Batch Volume', { placeholder: '5' }),
      numberField('yan', 'Target YAN (ppm)', { placeholder: '175' }),
    ],
  },
  {
    group: 'Recipe Building',
    id: 'build-batch',
    title: 'Build a Batch',
    method: 'POST',
    path: '/api/v1/build-batch',
    description: 'Core fields shown -- see /docs for every advanced override.',
    fields: [
      selectField('units', 'Units', ['us', 'metric'], { default: 'us' }),
      numberField('volume', 'Batch Volume', { placeholder: '5' }),
      numberField('yeastAbv', "Yeast's %ABV Tolerance", { placeholder: '18' }),
      numberField('ogOverride', 'Target OG (optional)'),
      selectField('yanRequirement', 'YAN Requirement', ['very_low', 'low', 'medium', 'high', 'kveik'], { default: 'medium' }),
      selectField('nutrientRegimen', 'Nutrient Regimen', ['tosna', 'k_dap', 'blount_elliott', 'tosna_k', 'o_k', 'advanced'], { default: 'blount_elliott' }),
      checkboxField('hot', 'Fermenting Hot (>=80F)'),
    ],
  },
  {
    group: 'Recipe Building',
    id: 'calculate-mead',
    title: 'Calculate Mead Recipe',
    method: 'POST',
    path: '/api/v1/calculate-mead',
    description: 'Give any two of target volume/gravity/ABV. Core fields shown -- see /docs for step feeding, additional sugars, and every advanced override.',
    fields: [
      selectField('units', 'Units', ['us', 'metric', 'imperial'], { default: 'us' }),
      numberField('targetVolume', 'Target Volume', { placeholder: '5' }),
      numberField('targetGravity', 'Target Gravity'),
      numberField('targetAbv', 'Target %ABV'),
      numberField('yeastAbv', "Yeast's %ABV Tolerance", { placeholder: '18' }),
      selectField('yanRequirement', 'YAN Requirement', ['very_low', 'low', 'medium', 'high', 'kveik'], { default: 'medium' }),
    ],
  },
  {
    group: 'Lookups',
    id: 'sugar-source',
    title: 'Sugar Source Lookup',
    method: 'GET',
    path: '/api/v1/sugar-sources/{name}',
    description: 'e.g. honey, blueberry, dried_apricots.',
    fields: [textField('name', 'Sugar Source Name', { required: true, placeholder: 'honey' })],
  },
  {
    group: 'Lookups',
    id: 'yeast-requirements',
    title: 'List Yeast YAN Requirements',
    method: 'GET',
    path: '/api/v1/yeast-requirements',
    fields: [],
  },
  {
    group: 'Lookups',
    id: 'volume-units',
    title: 'List Volume Units',
    method: 'GET',
    path: '/api/v1/volume-units',
    fields: [],
  },
  {
    group: 'Dates & Misc',
    id: 'days-between',
    title: 'Days Between Dates',
    method: 'POST',
    path: '/api/v1/dates/days-between',
    fields: [textField('date1', 'Date 1', { required: true, type: 'date' }), textField('date2', 'Date 2', { required: true, type: 'date' })],
  },
  {
    group: 'Dates & Misc',
    id: 'months-between',
    title: 'Months Between Dates',
    method: 'POST',
    path: '/api/v1/dates/months-between',
    fields: [
      textField('date1', 'Date 1', { required: true, type: 'date' }),
      textField('date2', 'Date 2', { required: true, type: 'date' }),
      checkboxField('roundUpFractionalMonths', 'Round Up Fractional Months'),
    ],
  },
  {
    group: 'Dates & Misc',
    id: 'hours-string',
    title: 'Nutrient Addition Timing String',
    method: 'POST',
    path: '/api/v1/hours-string',
    description: 'timing: "pitch", "break", an "hours,additionIndex" pair (e.g. "24,1"), or a plain number of hours.',
    fields: [textField('timing', 'Timing', { required: true, placeholder: '24,1' }), numberField('break3', '1/3 Sugar Break SG (only if timing is "break")')],
  },
];

function el(tag, attrs, children) {
  const node = document.createElement(tag);
  Object.entries(attrs || {}).forEach(([key, value]) => {
    if (key === 'class') node.className = value;
    else if (key === 'html') node.innerHTML = value;
    else if (key.startsWith('on')) node.addEventListener(key.slice(2), value);
    else node.setAttribute(key, value);
  });
  (children || []).forEach((child) => node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child));
  return node;
}

async function callApi(method, path, params) {
  let url = path;
  let body = null;
  const query = {};

  Object.entries(params).forEach(([key, value]) => {
    if (value === '' || value === null || value === undefined) return;
    if (url.includes(`{${key}}`)) {
      url = url.replace(`{${key}}`, encodeURIComponent(value));
    } else if (method === 'GET') {
      query[key] = value;
    } else {
      (body || (body = {}))[key] = value;
    }
  });

  if (method === 'GET' && Object.keys(query).length > 0) {
    url += '?' + new URLSearchParams(query).toString();
  }

  const response = await fetch(url, {
    method,
    headers: body ? { 'Content-Type': 'application/json' } : undefined,
    body: body ? JSON.stringify(body) : undefined,
  });
  return response.json();
}

function renderResult(container, result) {
  container.innerHTML = '';
  container.classList.toggle('result--error', !!result.error);
  container.appendChild(el('pre', {}, [JSON.stringify(result, null, 2)]));
}

function buildToolCard(tool) {
  const resultBox = el('div', { class: 'result' });
  const inputs = {};

  const fields = tool.fields.map((field) => {
    const inputId = `${tool.id}-${field.name}`;
    let input;
    if (field.type === 'select') {
      input = el(
        'select',
        { id: inputId, name: field.name },
        field.options.map((option) => el('option', { value: option, selected: option === field.default ? 'selected' : undefined }, [option]))
      );
    } else if (field.type === 'checkbox') {
      input = el('input', { id: inputId, name: field.name, type: 'checkbox' });
    } else {
      input = el('input', {
        id: inputId,
        name: field.name,
        type: field.type,
        step: field.step,
        placeholder: field.placeholder || '',
        required: field.required ? 'required' : undefined,
      });
    }
    inputs[field.name] = { field, input };
    return el('label', { class: 'field', for: inputId }, [field.label, input]);
  });

  const form = el(
    'form',
    {
      class: 'tool-form',
      onsubmit: async (event) => {
        event.preventDefault();
        const params = {};
        Object.entries(inputs).forEach(([name, { field, input }]) => {
          params[name] = field.type === 'checkbox' ? input.checked : input.value;
        });
        renderResult(resultBox, { loading: true });
        try {
          const result = await callApi(tool.method, tool.path, params);
          renderResult(resultBox, result);
        } catch (error) {
          renderResult(resultBox, { error: true, errorMessage: String(error) });
        }
      },
    },
    [...fields, el('button', { type: 'submit' }, [tool.fields.length ? 'Calculate' : 'Run'])]
  );

  return el('article', { class: 'tool-card' }, [
    el('h3', {}, [tool.title]),
    tool.description ? el('p', { class: 'tool-description' }, [tool.description]) : null,
    form,
    resultBox,
  ].filter(Boolean));
}

function renderCalculators() {
  const root = document.getElementById('calculators');
  const groups = {};
  TOOLS.forEach((tool) => {
    (groups[tool.group] || (groups[tool.group] = [])).push(tool);
  });

  Object.entries(groups).forEach(([groupName, tools]) => {
    root.appendChild(el('h2', { class: 'group-title' }, [groupName]));
    const grid = el('div', { class: 'tool-grid' }, tools.map(buildToolCard));
    root.appendChild(grid);
  });
}

// --- Chat ---

let chatMessages = [];
let currentUser = null;

const CHAT_SYSTEM_PROMPT =
  'You are MeadBot, a web assistant for a mead-making community, with access to mead-brewing ' +
  'calculators (ABV, calories, nutrients/SNA schedules, unit conversions, blending, full ' +
  'batch/recipe builds, sugar-source and yeast-requirement lookups, date/hours-string helpers) ' +
  'and two tools grounding you in https://wiki.meadtools.com, this community\'s authoritative ' +
  'mead-making reference: list_meadtools_wiki_pages (an index of pages -- title, url, category, ' +
  'a one-sentence summary, keywords, related_pages) and fetch_meadtools_wiki_page (fetches one ' +
  'page\'s text and links).\n\n' +
  'MANDATORY CALCULATOR-FIRST RULE: for any question involving a mead-making calculation -- ABV, ' +
  'calories, gravity/unit conversions, blending, or a nutrient/SNA schedule or full batch build -- ' +
  'you MUST call the matching calculator tool rather than computing or estimating it yourself, ' +
  'even if confident of the formula. If a calculator\'s required inputs are ambiguous or missing, ' +
  'ask the user rather than guessing values to fill them in.\n\n' +
  'NUMERIC CONSISTENCY RULE: only state a comparison or caveat about a calculated number ' +
  '("that\'s more than X", "not your full Y") if you have actually checked it against the ' +
  'numbers a calculator just returned. If unsure, state the number plainly instead.\n\n' +
  'MANDATORY WIKI-FIRST RULE: for any mead-making judgment question (recipe design, technique, ' +
  'troubleshooting, ingredient/yeast/nutrient choices, timing) that isn\'t a pure calculation, ' +
  'consult the wiki before answering -- call list_meadtools_wiki_pages first, using each entry\'s ' +
  'summary as your primary relevance signal, then fetch_meadtools_wiki_page for the matching ' +
  'url(s). Base your answer on what the wiki says, not your own training data, which is known to ' +
  'be unreliable for mead-making specifics. Cite pages you used inline as bare URLs next to the ' +
  'claims they support.\n\n' +
  'NEVER suggest deliberately stopping/interrupting a fermentation that is already in progress ' +
  '(e.g. cold-crashing early, adding stabilizer mid-ferment) to hit a target gravity/ABV -- this ' +
  'community considers it bad practice (stuck/stressed fermentation, off-flavors, refermentation ' +
  'risk). Point to legitimate alternatives instead: yeast strain selection, back-sweetening after ' +
  'fermentation has naturally finished, or step feeding.\n\n' +
  'Format replies as normal markdown (tables, bold, links all render fine here). Keep replies ' +
  'concise.';

function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

// A small hand-rolled markdown renderer (no dependency/build step, matching this project's style
// -- the only vendored JS anywhere is Swagger UI under /docs, wholesale rather than hand-maintained)
// covering what CHAT_SYSTEM_PROMPT tells the model it can use: headings, bold/italic, inline code
// and fenced code blocks, links (markdown and bare URLs), lists, blockquotes, and GFM-style pipe
// tables. Not a full CommonMark implementation -- just enough for typical LLM chat replies.

// Inline formatting, applied within a single block's already-HTML-escaped text -- safe to inject
// as innerHTML afterward since every tag it adds wraps escaped text, never raw model output.
function renderInline(escapedText) {
  // Pull out inline code spans first so `` `**not bold**` `` isn't touched by the rules below.
  const codeSpans = [];
  let html = escapedText.replace(/`([^`]+)`/g, (_, code) => {
    codeSpans.push(code);
    return ` ${codeSpans.length - 1} `;
  });

  html = html.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
  html = html.replace(/\*\*([^*]+)\*\*|__([^_]+)__/g, (_, a, b) => `<strong>${a ?? b}</strong>`);
  html = html.replace(/\*([^*]+)\*|(?<![\w])_([^_]+)_(?![\w])/g, (_, a, b) => `<em>${a ?? b}</em>`);
  // Bare URLs, skipping ones already wrapped by the markdown-link rule above (href="..." or the
  // link text itself, which for a bare-URL-as-link-text markdown link would duplicate otherwise).
  html = html.replace(/(^|[^">])(https?:\/\/[^\s<]+)/g, (m, pre, url) => `${pre}<a href="${url}" target="_blank" rel="noopener">${url}</a>`);

  return html.replace(/ (\d+) /g, (_, i) => `<code>${codeSpans[Number(i)]}</code>`);
}

function splitTableRow(line) {
  return line.trim().replace(/^\|/, '').replace(/\|$/, '').split('|').map((cell) => cell.trim());
}

const TABLE_SEPARATOR_RE = /^\s*\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?\s*$/;
const BLOCK_START_RE = /^(```|#{1,6}\s|>\s?|[-*+]\s|\d+[.)]\s)/;

function renderMarkdownish(text) {
  const lines = text.replace(/\r\n/g, '\n').split('\n');
  const blocks = [];
  let i = 0;

  while (i < lines.length) {
    const line = lines[i];

    if (line.trim() === '') {
      i++;
      continue;
    }

    const fence = line.match(/^```(\S*)\s*$/);
    if (fence) {
      const codeLines = [];
      i++;
      while (i < lines.length && !/^```\s*$/.test(lines[i])) {
        codeLines.push(lines[i]);
        i++;
      }
      i++; // skip closing fence (or end of text, if the model never closed it)
      blocks.push(`<pre><code>${escapeHtml(codeLines.join('\n'))}</code></pre>`);
      continue;
    }

    const heading = line.match(/^(#{1,6})\s+(.*)$/);
    if (heading) {
      const level = heading[1].length;
      blocks.push(`<h${level}>${renderInline(escapeHtml(heading[2]))}</h${level}>`);
      i++;
      continue;
    }

    if (/^>\s?/.test(line)) {
      const quoteLines = [];
      while (i < lines.length && /^>\s?/.test(lines[i])) {
        quoteLines.push(lines[i].replace(/^>\s?/, ''));
        i++;
      }
      blocks.push(`<blockquote>${renderInline(escapeHtml(quoteLines.join('\n'))).replace(/\n/g, '<br>')}</blockquote>`);
      continue;
    }

    if (line.includes('|') && i + 1 < lines.length && TABLE_SEPARATOR_RE.test(lines[i + 1])) {
      const headerCells = splitTableRow(line);
      i += 2;
      const bodyRows = [];
      while (i < lines.length && lines[i].includes('|') && lines[i].trim() !== '') {
        bodyRows.push(splitTableRow(lines[i]));
        i++;
      }
      const headHtml = headerCells.map((c) => `<th>${renderInline(escapeHtml(c))}</th>`).join('');
      const bodyHtml = bodyRows
        .map((row) => `<tr>${row.map((c) => `<td>${renderInline(escapeHtml(c))}</td>`).join('')}</tr>`)
        .join('');
      blocks.push(`<table><thead><tr>${headHtml}</tr></thead><tbody>${bodyHtml}</tbody></table>`);
      continue;
    }

    if (/^[-*+]\s+/.test(line)) {
      const items = [];
      while (i < lines.length && /^[-*+]\s+/.test(lines[i])) {
        items.push(lines[i].replace(/^[-*+]\s+/, ''));
        i++;
      }
      blocks.push(`<ul>${items.map((item) => `<li>${renderInline(escapeHtml(item))}</li>`).join('')}</ul>`);
      continue;
    }

    if (/^\d+[.)]\s+/.test(line)) {
      const items = [];
      while (i < lines.length && /^\d+[.)]\s+/.test(lines[i])) {
        items.push(lines[i].replace(/^\d+[.)]\s+/, ''));
        i++;
      }
      blocks.push(`<ol>${items.map((item) => `<li>${renderInline(escapeHtml(item))}</li>`).join('')}</ol>`);
      continue;
    }

    const paraLines = [];
    while (i < lines.length && lines[i].trim() !== '' && (paraLines.length === 0 || !BLOCK_START_RE.test(lines[i]))) {
      paraLines.push(lines[i]);
      i++;
    }
    blocks.push(`<p>${renderInline(escapeHtml(paraLines.join('\n'))).replace(/\n/g, '<br>')}</p>`);
  }

  return blocks.join('');
}

function appendChatMessage(role, text) {
  const log = document.getElementById('chat-log');
  const bubble = el('div', { class: `chat-message chat-message--${role}`, html: renderMarkdownish(text) });
  log.appendChild(bubble);
  log.scrollTop = log.scrollHeight;
  return bubble;
}

async function refreshLoginState() {
  const me = await fetch('/api/v1/auth/me').then((r) => r.json());
  currentUser = me.loggedIn ? me.user : null;

  const status = document.getElementById('chat-auth-status');
  const loginBtn = document.getElementById('chat-login-btn');
  const logoutBtn = document.getElementById('chat-logout-btn');
  const chatForm = document.getElementById('chat-form');

  if (currentUser) {
    status.textContent = `Logged in as ${currentUser.username}`;
    loginBtn.hidden = true;
    logoutBtn.hidden = false;
    chatForm.hidden = false;
  } else {
    status.textContent = 'Log in with Discord to chat.';
    loginBtn.hidden = false;
    logoutBtn.hidden = true;
    chatForm.hidden = true;
  }
}

function initChat() {
  document.getElementById('chat-login-btn').addEventListener('click', () => {
    window.location.href = '/api/v1/auth/discord/login';
  });

  document.getElementById('chat-logout-btn').addEventListener('click', async () => {
    await fetch('/api/v1/auth/logout', { method: 'POST' });
    chatMessages = [];
    document.getElementById('chat-log').innerHTML = '';
    refreshLoginState();
  });

  document.getElementById('chat-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const input = document.getElementById('chat-input');
    const text = input.value.trim();
    if (!text) return;

    if (chatMessages.length === 0) {
      chatMessages.push({ role: 'system', content: CHAT_SYSTEM_PROMPT });
    }
    chatMessages.push({ role: 'user', content: text });
    appendChatMessage('user', text);
    input.value = '';
    input.disabled = true;

    const pending = appendChatMessage('assistant', 'Thinking...');
    try {
      const result = await fetch('/api/v1/chat/web', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ messages: chatMessages }),
      }).then((r) => r.json());

      if (result.error) {
        pending.classList.add('chat-message--error');
        pending.innerHTML = escapeHtml('Error: ' + result.errorMessage);
        chatMessages.pop(); // drop the user turn too, so a retry doesn't resend a broken history
        if (result.requiresLogin) {
          refreshLoginState();
        }
        return;
      }

      pending.innerHTML = renderMarkdownish(result.reply);
      chatMessages = result.messages;
    } catch (error) {
      pending.classList.add('chat-message--error');
      pending.innerHTML = escapeHtml('Failed to reach the chat API: ' + error);
      chatMessages.pop();
    } finally {
      input.disabled = false;
      input.focus();
      document.getElementById('chat-log').scrollTop = document.getElementById('chat-log').scrollHeight;
    }
  });

  refreshLoginState();
}

// --- Tabs ---

function initTabs() {
  document.querySelectorAll('.tab-button').forEach((button) => {
    button.addEventListener('click', () => {
      document.querySelectorAll('.tab-button').forEach((b) => b.classList.remove('is-active'));
      document.querySelectorAll('.tab-panel').forEach((p) => p.classList.remove('is-active'));
      button.classList.add('is-active');
      document.getElementById(button.dataset.tab).classList.add('is-active');
    });
  });
}

document.addEventListener('DOMContentLoaded', () => {
  renderCalculators();
  initChat();
  initTabs();
});
