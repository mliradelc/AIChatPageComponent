/**
 * This file is part of the AIChatPageComponent plugin for ILIAS.
 *
 * Copyright (c) University of Cologne, CompetenceCenter E-Learning
 *
 * The plugin is licensed with the GPL-3.0,
 * see https://www.gnu.org/licenses/gpl-3.0.en.html
 * You should have received a copy of said license along with the
 * source code, too.
 */

/**
 * Frontend of the AI chat page element
 *
 * Each element with the class ai-chat-container gets an AIChatPageComponent instance.
 * Markdown is rendered with marked.js if available. Rendered HTML is cleaned
 * with DOMPurify; without DOMPurify, answers are shown as plain text.
 * highlight.js and KaTeX are loaded on demand for code blocks and formulas.
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */

/**
 * Enables console output for development
 * @type {boolean}
 */
const AICHAT_DEBUG = false;

/**
 * console.log if AICHAT_DEBUG is set
 * @type {Function}
 */
const debug = AICHAT_DEBUG ? console.log.bind(console) : () => {};

/**
 * console.error if AICHAT_DEBUG is set
 * @type {Function}
 */
const debugError = AICHAT_DEBUG ? console.error.bind(console) : () => {};

/**
 * Base URL of the bundled libraries (js/vendor/), derived from the URL of this script
 * @type {string}
 */
const AICHAT_VENDOR_URL = document.currentScript && document.currentScript.src
  ? new URL('vendor/', document.currentScript.src).href
  : '';

/**
 * Pending or completed loads of bundled libraries, by path
 * @type {Object<string, Promise<void>>}
 */
const aiChatVendorLoads = {};

/**
 * Load a bundled script or stylesheet once
 *
 * @param {string} path - Path below js/vendor/
 * @param {string} [globalName] - Global the script defines; if it exists, the script is not loaded
 * @returns {Promise<void>}
 */
function loadAIChatVendor(path, globalName = '') {
  if (globalName && window[globalName]) {
    return Promise.resolve();
  }
  if (!aiChatVendorLoads[path]) {
    aiChatVendorLoads[path] = new Promise((resolve, reject) => {
      let element;
      if (path.endsWith('.css')) {
        element = document.createElement('link');
        element.rel = 'stylesheet';
        element.href = AICHAT_VENDOR_URL + path;
      } else {
        element = document.createElement('script');
        element.src = AICHAT_VENDOR_URL + path;
      }
      element.onload = () => resolve();
      element.onerror = () => {
        delete aiChatVendorLoads[path];
        reject(new Error(`Failed to load ${path}`));
      };
      document.head.appendChild(element);
    });
  }
  return aiChatVendorLoads[path];
}

/**
 * Escape text for use in HTML, also inside attribute values
 *
 * @param {string} text
 * @returns {string}
 */
function escapeAIChatHtml(text) {
  return String(text)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

/** Superscript digits in the order 1-9, 0 */
const AICHAT_SUPERSCRIPTS = '¹²³⁴⁵⁶⁷⁸⁹⁰';

/**
 * Rendered formulas (KaTeX HTML) by display mode and TeX source
 * @type {Map<string, string>}
 */
const aiChatFormulaCache = new Map();

/** Whether the Markdown extensions are registered */
let aiChatMarkedConfigured = false;

/**
 * Register the Markdown extensions for citation markers and formulas
 *
 * Extensions are only applied outside of code, so [1] or $x$ in code stays unchanged.
 * Citations ([1], [1, 2], [1][2], ^1, ¹) become placeholders that are replaced by
 * source chips once the sources are known. Formulas ($…$, $$…$$, \(…\), \[…\])
 * become placeholders that are rendered with KaTeX. Single dollar signs only
 * delimit a formula if no space follows the opening and precedes the closing sign
 * and no digit follows the closing sign, so prices like "5$ to 10$" stay text.
 */
function configureAIChatMarked() {
  if (aiChatMarkedConfigured) {
    return;
  }
  aiChatMarkedConfigured = true;

  const citation = {
    name: 'aiChatCitation',
    level: 'inline',
    start(src) {
      const index = src.search(/\[\d|\^\d|[¹²³⁴⁵⁶⁷⁸⁹⁰]/);
      return index < 0 ? undefined : index;
    },
    tokenizer(src) {
      let match = /^(?:\[\d+(?:\s*,\s*\d+)*\])+/.exec(src);
      if (match) {
        // [1](https://…) is a link
        if (src.charAt(match[0].length) === '(') {
          return undefined;
        }
        return { type: 'aiChatCitation', raw: match[0], ids: match[0].match(/\d+/g).map(Number) };
      }
      match = /^\^(\d+)/.exec(src);
      if (match) {
        return { type: 'aiChatCitation', raw: match[0], ids: [Number(match[1])] };
      }
      match = /^[¹²³⁴⁵⁶⁷⁸⁹⁰]+/.exec(src);
      if (match) {
        const digits = [...match[0]].map((char) => (AICHAT_SUPERSCRIPTS.indexOf(char) + 1) % 10).join('');
        return { type: 'aiChatCitation', raw: match[0], ids: [Number(digits)] };
      }
      return undefined;
    },
    renderer(token) {
      return `<span class="ai-chat-cite" data-cite="${token.ids.join(',')}">${escapeAIChatHtml(token.raw)}</span>`;
    },
  };

  const mathInline = {
    name: 'aiChatMathInline',
    level: 'inline',
    start(src) {
      const match = /\\[([]|\$\$|(^|[^\w\\$])\$(?=\S)/.exec(src);
      if (!match) {
        return undefined;
      }
      return match.index + (match[1] ? match[1].length : 0);
    },
    tokenizer(src) {
      const patterns = [
        [/^\\\(([\s\S]+?)\\\)/, false],
        [/^\\\[([\s\S]+?)\\\]/, true],
        [/^\$\$([^$]+?)\$\$/, true],
        [/^\$(?=\S)([^$\n]+?)(?<=\S)\$(?!\d)/, false],
      ];
      const found = patterns
        .map(([pattern, display]) => ({ match: pattern.exec(src), display }))
        .find(({ match }) => match !== null);
      if (!found) {
        return undefined;
      }
      return {
        type: 'aiChatMathInline', raw: found.match[0], text: found.match[1].trim(), display: found.display,
      };
    },
    renderer(token) {
      return `<span class="ai-chat-math" data-display="${token.display ? 1 : 0}">${escapeAIChatHtml(token.text)}</span>`;
    },
  };

  const mathBlock = {
    name: 'aiChatMathBlock',
    level: 'block',
    start(src) {
      const match = /^ {0,3}(?:\$\$|\\\[)/m.exec(src);
      return match ? match.index : undefined;
    },
    tokenizer(src) {
      const match = /^ {0,3}\$\$([\s\S]+?)\$\$[ \t]*(?:\n|$)/.exec(src)
        || /^ {0,3}\\\[([\s\S]+?)\\\][ \t]*(?:\n|$)/.exec(src);
      if (!match) {
        return undefined;
      }
      return { type: 'aiChatMathBlock', raw: match[0], text: match[1].trim() };
    },
    renderer(token) {
      return `<div class="ai-chat-math" data-display="1">${escapeAIChatHtml(token.text)}</div>\n`;
    },
  };

  window.marked.use({ extensions: [citation, mathInline, mathBlock] });
}

/**
 * Chat element on an ILIAS page: message exchange with api.php, streaming,
 * file uploads, source display and chat history
 */
class AIChatPageComponent {
  /**
   * Create the chat for a container element
   *
   * @param {string} containerId - The ID of the HTML container element
   * @throws {Error} When container element is not found
   */
  constructor(containerId) {
    this.containerId = containerId;
    this.container = document.getElementById(containerId);
    this.isLoading = false;
    this.messageHistory = [];
    this.attachments = [];
    this.currentRequest = null;

    if (!this.container) {
      debugError('AIChatPageComponent: Container not found with ID:', containerId);
      return;
    }

    this.init();
  }

  /**
   * Read DOM references and configuration from the container
   *
   * @throws {Error} When required DOM elements are not found
   */
  init() {
    this.messagesArea = this.container.querySelector('.ai-chat-messages');
    this.scrollButton = this.container.querySelector('.ai-chat-scroll-bottom');
    // New content keeps the view at the end only while the user is there
    this.followMessages = true;
    this.inputArea = this.container.querySelector('.ai-chat-input');
    this.sendButton = this.container.querySelector('.ai-chat-send');
    this.welcomeMsg = this.container.querySelector('.ai-chat-welcome');
    // Answer placeholder with the thinking indicator while a request is running
    this.pendingMessage = null;
    // State of the answer being streamed; only one answer is streamed at a time
    this.streamState = AIChatPageComponent.createStreamState();
    this.srStatus = this.container.querySelector('.ai-chat-sr-status');

    this.attachBtn = this.container.querySelector('.ai-chat-attach-btn');
    this.fileInput = this.container.querySelector('.ai-chat-file-input');
    this.attachmentsArea = this.container.querySelector('.ai-chat-attachments');

    const enableChatUploads = this.container.dataset.enableChatUploads === 'true';
    if (!this.attachmentsArea && enableChatUploads) {
      debug('AIChatPageComponent: Creating missing attachments area (fallback)');
      this.attachmentsArea = document.createElement('div');
      this.attachmentsArea.className = 'ai-chat-attachments';
      this.attachmentsArea.style.display = 'none';
      const composerArea = this.container.querySelector('.ai-chat-composer');
      if (composerArea) {
        this.container.insertBefore(this.attachmentsArea, composerArea);
      }
    }

    this.attachmentsList = this.attachmentsArea;
    this.clearAttachmentsBtn = this.container.querySelector('.ai-chat-clear-attachments');

    debug('AIChatPageComponent: DOM elements initialized', {
      container: !!this.container,
      attachBtn: !!this.attachBtn,
      fileInput: !!this.fileInput,
      attachmentsArea: !!this.attachmentsArea,
      attachmentsList: !!this.attachmentsList,
      clearAttachmentsBtn: !!this.clearAttachmentsBtn,
      attachmentsAreaCreated: this.container.querySelector('.ai-chat-attachments') !== null,
    });
    this.charCounter = this.container.querySelector('.ai-chat-char-count');
    this.charLimitElement = this.container.querySelector('.ai-chat-char-limit');

    this.clearChatBtn = this.container.querySelector('.ai-chat-clear-btn');

    this.chatId = this.container.dataset.chatId;
    this.apiUrl = this.container.dataset.apiUrl;
    this.systemPrompt = this.container.dataset.systemPrompt;
    this.maxMemory = parseInt(this.container.dataset.maxMemory, 10) || 10;
    this.charLimit = parseInt(this.container.dataset.charLimit, 10) || 2000;
    this.persistent = this.container.dataset.persistent === 'true';
    this.aiService = this.container.dataset.aiService || 'default';
    this.enableChatUploads = this.container.dataset.enableChatUploads === 'true';
    this.enableStreaming = this.container.dataset.enableStreaming === 'true';
    this.isAnonymous = this.container.dataset.isAnonymous === 'true';
    this.serviceUnavailable = this.container.dataset.serviceUnavailable === 'true';
    this.isAdmin = this.container.dataset.isAdmin === 'true';

    // History of anonymous users; kept in memory only, never stored
    this.anonymousHistory = [];

    this.globalChatUploadsEnabled = true;
    this.allowedFileTypes = [];

    this.lang = AIChatPageComponent.readLang(this.container);

    this.pageId = parseInt(this.container.dataset.pageId, 10) || 0;
    this.parentId = parseInt(this.container.dataset.parentId, 10) || 0;
    this.parentType = this.container.dataset.parentType || '';
    this.includePageContext = this.container.dataset.includePageContext === 'true';
    this.backgroundFiles = this.container.dataset.backgroundFiles || '[]';

    debug('AIChatPageComponent: Initialized with config:', {
      chatId: this.chatId,
      apiUrl: this.apiUrl ? 'set' : 'not set',
      maxMemory: this.maxMemory,
      charLimit: this.charLimit,
      persistent: this.persistent,
      aiService: this.aiService,
    });

    if (!this.sendButton || !this.inputArea) {
      debugError('AIChatPageComponent: Required elements not found');
      return;
    }

    this.bindEvents();
    this.initTheme();
    this.updateSendButtonState();

    if (this.serviceUnavailable) {
      this.disableInputForUnavailableService();
    } else {
      this.checkUploadConfiguration();
    }

    this.loadChatHistory();
  }

  static get THEME_STORAGE_KEY() { return 'ai_chat_theme'; }

  static get ICON_COPY() {
    return '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"/></svg>';
  }

  static get ICON_DOWNLOAD() {
    return '<svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" focusable="false"><path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5z"/><path d="M7.646 11.854a.5.5 0 0 0 .708 0l3-3a.5.5 0 0 0-.708-.708L8.5 10.293V1.5a.5.5 0 0 0-1 0v8.793L5.354 8.146a.5.5 0 1 0-.708.708l3 3z"/></svg>';
  }

  /** Distance from the end of the messages, in pixels, still treated as "at the end" */
  static get SCROLL_END_TOLERANCE() { return 48; }

  /**
   * Language strings from the data attributes of a chat container
   *
   * @param {HTMLElement} container
   * @returns {Object}
   */
  static readLang(container) {
    return {
      copyMessageTitle: container.dataset.copyMessageTitle || 'Copy message',
      regenerateResponseTitle: container.dataset.regenerateResponseTitle || 'Regenerate response',
      messageCopied: container.dataset.messageCopied || 'Copied!',
      messageCopyFailed: container.dataset.messageCopyFailed || 'Failed to copy',
      thinkingHeader: container.dataset.thinkingHeader || 'Thinking...',
      thinking: container.dataset.loadingText || 'Thinking...',
      generationStopped: container.dataset.generationStopped || 'Generation stopped by user.',
      regenerateFailed: container.dataset.regenerateFailed || 'Failed to regenerate response. Please try again.',
      welcomeMessage: container.dataset.welcomeMessage || 'Start a conversation...',
      stopGeneration: container.dataset.stopGeneration || 'Stop generation',
      newMessageAria: container.dataset.newMessageAria || 'New message received',
      sourcesLabel: container.dataset.sourcesLabel || 'Quellen',
      ragIncompleteNotice: container.dataset.ragIncompleteNotice
        || 'Some background files have not been processed yet. The answer may be incomplete.',
      ragUploadStatus: {
        pending: container.dataset.ragUploadProcessing || 'Being processed for the document search …',
        processing: container.dataset.ragUploadProcessing || 'Being processed for the document search …',
        completed: container.dataset.ragUploadCompleted || 'Ready for the document search',
        failed: container.dataset.ragUploadFailed || 'Processing failed, a new attempt follows',
        empty: container.dataset.ragUploadEmpty || 'No searchable text found',
        scanned: container.dataset.ragUploadScanned || 'Ready (scanned document: text recognition may be incomplete)',
      },
      pageLabel: container.dataset.pageLabel || 'Seite',
      pagesLabel: container.dataset.pagesLabel || 'Seiten',
      tableCopy: container.dataset.tableCopy || 'Copy table',
      tableExportCsv: container.dataset.tableExportCsv || 'Export as CSV',
      codeCopy: container.dataset.codeCopy || 'Copy code',
      citationMoreSource: container.dataset.citationMoreSource || '1 more source',
      citationMoreSources: container.dataset.citationMoreSources || '%s more sources',
      sourcePrevious: container.dataset.sourcePrevious || 'Previous source',
      sourceNext: container.dataset.sourceNext || 'Next source',
      alerts: {
        note: container.dataset.alertNote || 'Note',
        tip: container.dataset.alertTip || 'Tip',
        important: container.dataset.alertImportant || 'Important',
        warning: container.dataset.alertWarning || 'Warning',
        caution: container.dataset.alertCaution || 'Caution',
      },
    };
  }

  /**
   * State of a streamed answer: pending frame and sources received before the answer
   *
   * @returns {{renderPending: boolean, sources: Array|null, sourcesRow: HTMLElement|null}}
   */
  static createStreamState() {
    return { renderPending: false, sources: null, sourcesRow: null };
  }

  /** Maximum width of the unfolded file name of a source chip, in pixels */
  static get CHIP_NAME_WIDTH() { return 160; }

  /** URLs allowed in rendered answers: http, https, mailto and relative URLs */
  static get SAFE_URL() { return /^(?:(?:https?|mailto):|[^a-z]|[a-z+.-]+(?:[^a-z+.\-:]|$))/i; }

  static get ICON_MOON() {
    return `<svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" focusable="false">
            <path d="M6 .278a.768.768 0 0 1 .08.858 7.208 7.208 0 0 0-.878 3.46c0 4.021 3.278 7.277 7.318 7.277.527 0 1.04-.055 1.533-.16a.787.787 0 0 1 .81.316.733.733 0 0 1-.031.893A8.349 8.349 0 0 1 8.344 16C3.734 16 0 12.286 0 7.71 0 4.266 2.114 1.312 5.124.06A.752.752 0 0 1 6 .278z"/>
        </svg>`;
  }

  static get ICON_SUN() {
    return `<svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" focusable="false">
            <path d="M8 11a3 3 0 1 1 0-6 3 3 0 0 1 0 6zm0 1a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM8 0a.5.5 0 0 1 .5.5v2a.5.5 0 0 1-1 0v-2A.5.5 0 0 1 8 0zm0 13a.5.5 0 0 1 .5.5v2a.5.5 0 0 1-1 0v-2A.5.5 0 0 1 8 13zm8-5a.5.5 0 0 1-.5.5h-2a.5.5 0 0 1 0-1h2a.5.5 0 0 1 .5.5zM3 8a.5.5 0 0 1-.5.5h-2a.5.5 0 0 1 0-1h2A.5.5 0 0 1 3 8zm10.657-5.657a.5.5 0 0 1 0 .707l-1.414 1.415a.5.5 0 1 1-.707-.708l1.414-1.414a.5.5 0 0 1 .707 0zm-9.193 9.193a.5.5 0 0 1 0 .707L3.05 13.657a.5.5 0 0 1-.707-.707l1.414-1.414a.5.5 0 0 1 .707 0zm9.193 2.121a.5.5 0 0 1-.707 0l-1.414-1.414a.5.5 0 0 1 .707-.707l1.414 1.414a.5.5 0 0 1 0 .707zM4.464 4.465a.5.5 0 0 1-.707 0L2.343 3.05a.5.5 0 1 1 .707-.707l1.414 1.414a.5.5 0 0 1 0 .707z"/>
        </svg>`;
  }

  initTheme() {
    const saved = window.localStorage.getItem(AIChatPageComponent.THEME_STORAGE_KEY);
    let theme;
    if (saved === 'light' || saved === 'dark') {
      theme = saved;
    } else if (window.matchMedia('(prefers-color-scheme: light)').matches) {
      theme = 'light';
    } else {
      theme = 'dark';
    }
    this.container.dataset.theme = theme;
    this.updateThemeButton();
  }

  toggleTheme() {
    const next = this.container.dataset.theme === 'light' ? 'dark' : 'light';
    this.container.dataset.theme = next;
    window.localStorage.setItem(AIChatPageComponent.THEME_STORAGE_KEY, next);
    this.updateThemeButton();
  }

  updateThemeButton() {
    const btn = this.container.querySelector('.ai-chat-theme-toggle');
    if (!btn) return;
    const isDark = this.container.dataset.theme === 'dark';
    // The icon shows the theme the button switches to
    btn.innerHTML = isDark ? AIChatPageComponent.ICON_SUN : AIChatPageComponent.ICON_MOON;
    btn.title = isDark
      ? (btn.dataset.titleLight || btn.title)
      : (btn.dataset.titleDark || btn.title);
  }

  /**
   * Load the upload configuration and global limits from the server
   *
   * @returns {Promise<void>}
   * @throws {Error} When API request fails
   */
  async checkUploadConfiguration() {
    try {
      const response = await fetch(this.apiUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          action: 'get_global_config',
          chat_id: this.chatId,
          config_type: 'all',
        }),
      });

      if (!response.ok) {
        debugError('AIChatPageComponent: Upload config check failed');
        return;
      }

      const data = await response.json();

      debug('AIChatPageComponent: Global config API response', {
        success: data.success,
        upload_enabled: data.upload_enabled,
        max_char_limit: data.max_char_limit,
        max_memory_limit: data.max_memory_limit,
        allowed_extensions: data.allowed_extensions?.length || 0,
        rawData: data,
      });

      if (data.success) {
        this.globalChatUploadsEnabled = data.upload_enabled;

        // Accept values from the server (MIME types and extensions)
        this.allowedAcceptValues = data.allowed_accept_values || [];

        this.allowedFileTypes = data.allowed_mime_types || [];
        this.allowedExtensions = data.allowed_extensions || [];

        debug('AIChatPageComponent: Processed allowed file types:', {
          acceptValues: this.allowedAcceptValues,
          mimeTypes: this.allowedFileTypes,
          extensions: this.allowedExtensions,
        });

        this.applyGlobalLimits(data);

        this.updateFileInputAcceptAttribute();

        // Uploads require the chat setting and the global setting
        const effectiveUploadsEnabled = this.enableChatUploads && this.globalChatUploadsEnabled;

        if (!effectiveUploadsEnabled && this.enableChatUploads) {
          this.hideFileUploadElements();
          debug('AIChatPageComponent: Chat uploads disabled by global administrator settings');
        }

        debug('AIChatPageComponent: Global configuration loaded', {
          finalCharLimit: this.charLimit,
          finalMaxMemory: this.maxMemory,
          globalConfigReceived: {
            max_char_limit: data.max_char_limit,
            max_memory_limit: data.max_memory_limit,
          },
        });

        debug('AIChatPageComponent: Upload configuration applied', {
          pageComponentEnabled: this.enableChatUploads,
          globalEnabled: this.globalChatUploadsEnabled,
          effectiveEnabled: effectiveUploadsEnabled,
          allowedTypes: this.allowedFileTypes,
          allowedExtensions: this.allowedExtensions,
        });
      }
    } catch (error) {
      debugError('AIChatPageComponent: Error checking global configuration:', error);
    }
  }

  /**
   * Apply global limits that are more restrictive than the chat settings
   *
   * @param {Object} globalConfig - Global configuration data from server
   */
  applyGlobalLimits(globalConfig) {
    const originalCharLimit = this.charLimit;
    const originalMaxMemory = this.maxMemory;

    debug('AIChatPageComponent: Applying global limits', {
      currentCharLimit: this.charLimit,
      currentMaxMemory: this.maxMemory,
      globalCharLimit: globalConfig.max_char_limit,
      globalMemoryLimit: globalConfig.max_memory_limit,
    });

    // The global limit applies if it is more restrictive
    debug('AIChatPageComponent: Checking global character limit', {
      hasGlobalLimit: !!globalConfig.max_char_limit,
      globalLimitValue: globalConfig.max_char_limit,
      globalLimitGreaterZero: globalConfig.max_char_limit > 0,
      localLimit: this.charLimit,
      shouldApply: globalConfig.max_char_limit && globalConfig.max_char_limit > 0 && this.charLimit > globalConfig.max_char_limit,
    });

    if (globalConfig.max_char_limit && globalConfig.max_char_limit > 0) {
      if (this.charLimit > globalConfig.max_char_limit) {
        this.charLimit = globalConfig.max_char_limit;
        debug('AIChatPageComponent: Character limit REDUCED by global admin setting', {
          original: originalCharLimit,
          enforced: this.charLimit,
          reason: 'Global administrator limit override',
        });

        if (this.charLimitElement) {
          debug('AIChatPageComponent: Updating character limit display', {
            element: this.charLimitElement,
            newValue: this.charLimit,
          });
          this.charLimitElement.textContent = this.charLimit;
        } else {
          debug('AIChatPageComponent: Character limit element not found!');
        }
      } else {
        debug('AIChatPageComponent: Local character limit is within global limit, no change needed', {
          local: this.charLimit,
          global: globalConfig.max_char_limit,
        });
      }
    } else {
      debug('AIChatPageComponent: No global character limit set or limit is 0');
    }

    // The global limit applies if it is more restrictive
    if (globalConfig.max_memory_limit && globalConfig.max_memory_limit > 0) {
      if (this.maxMemory > globalConfig.max_memory_limit) {
        this.maxMemory = globalConfig.max_memory_limit;
        debug('AIChatPageComponent: Memory limit reduced by global admin setting', {
          original: originalMaxMemory,
          enforced: this.maxMemory,
          reason: 'Global administrator limit override',
        });
      }
    }

    if (this.charLimit !== originalCharLimit || this.maxMemory !== originalMaxMemory) {
      debug('AIChatPageComponent: Global administrator limits applied', {
        charLimit: {
          original: originalCharLimit,
          enforced: this.charLimit,
          overridden: this.charLimit !== originalCharLimit,
        },
        maxMemory: {
          original: originalMaxMemory,
          enforced: this.maxMemory,
          overridden: this.maxMemory !== originalMaxMemory,
        },
      });

      if (this.container.dataset.showGlobalLimitInfo === 'true') {
        const limitInfo = [];
        if (this.charLimit !== originalCharLimit) {
          limitInfo.push(`Character limit: ${this.charLimit}`);
        }
        if (this.maxMemory !== originalMaxMemory) {
          limitInfo.push(`Memory limit: ${this.maxMemory} messages`);
        }

        if (limitInfo.length > 0) {
          this.showGlobalLimitInfo(limitInfo.join(', '));
        }
      }
    } else {
      debug('AIChatPageComponent: Local settings within global limits, no overrides needed');
    }
  }

  /**
   * Briefly show which global limits apply
   *
   * @param {string} limitInfo - Description of applied limits
   */
  showGlobalLimitInfo(limitInfo) {
    const infoDiv = document.createElement('div');
    infoDiv.className = 'ai-chat-global-limit-info';
    infoDiv.style.cssText = `
            position: absolute;
            top: -30px;
            right: 0;
            background: #f8f9fa;
            color: #6c757d;
            font-size: 0.8em;
            padding: 4px 8px;
            border-radius: 4px;
            border: 1px solid #dee2e6;
            z-index: 1000;
            opacity: 0;
            transition: opacity 0.3s ease;
            pointer-events: none;
        `;

    infoDiv.textContent = `Administrator limits applied: ${limitInfo}`;

    this.container.style.position = 'relative';
    this.container.appendChild(infoDiv);

    setTimeout(() => {
      infoDiv.style.opacity = '1';
    }, 100);

    setTimeout(() => {
      infoDiv.style.opacity = '0';
      setTimeout(() => {
        if (infoDiv.parentNode) {
          infoDiv.parentNode.removeChild(infoDiv);
        }
      }, 300);
    }, 5000);
  }

  /**
   * Register the event listeners
   */
  bindEvents() {
    // Copy buttons of code blocks; delegated, because the markup is created dynamically
    this.container.addEventListener('click', (event) => {
      const copyButton = event.target.closest('.ai-chat-code-copy');
      if (copyButton) {
        copyCodeToClipboard(copyButton);
        return;
      }

      const tableButton = event.target.closest('.ai-chat-table-copy, .ai-chat-table-csv');
      if (tableButton) {
        const table = tableButton.closest('.ai-chat-table-wrap').querySelector('table');
        if (tableButton.classList.contains('ai-chat-table-copy')) {
          const rows = AIChatPageComponent.tableToRows(table).map((row) => row.join('\t'));
          copyTextToClipboard(rows.join('\n'), tableButton);
        } else {
          AIChatPageComponent.downloadTableAsCsv(table);
        }
      }
    });

    this.messagesArea.addEventListener('scroll', () => {
      this.followMessages = this.isAtMessagesEnd();
      this.updateScrollButton();
    }, { passive: true });

    // Images are loaded after the message was added and enlarge it
    this.messagesArea.addEventListener('load', () => this.scrollToBottom(), true);

    // Content grows after it was added (formulas, highlighting, fonts), and a chat
    // that is hidden when the page is loaded has no height yet: the end stays in
    // view as long as the user has not scrolled up
    if (typeof window.ResizeObserver === 'function' && typeof window.MutationObserver === 'function') {
      const followResize = new window.ResizeObserver(() => {
        if (this.followMessages) {
          this.messagesArea.scrollTop = this.messagesArea.scrollHeight;
        }
        this.updateScrollButton();
      });
      followResize.observe(this.messagesArea);
      [...this.messagesArea.children].forEach((child) => followResize.observe(child));
      new window.MutationObserver((mutations) => {
        mutations.forEach((mutation) => mutation.addedNodes.forEach((node) => {
          if (node.nodeType === window.Node.ELEMENT_NODE) {
            followResize.observe(node);
          }
        }));
      }).observe(this.messagesArea, { childList: true });
    }

    if (this.scrollButton) {
      this.scrollButton.addEventListener('click', () => {
        const reduceMotion = typeof window.matchMedia === 'function'
          && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        this.followMessages = true;
        this.messagesArea.scrollTo({ top: this.messagesArea.scrollHeight, behavior: reduceMotion ? 'auto' : 'smooth' });
      });
    }

    this.sendButton.addEventListener('click', (e) => {
      e.preventDefault();
      if (this.isLoading) {
        this.stopGeneration();
      } else {
        this.sendMessage();
      }
    });

    // Enter sends, Shift+Enter inserts a line break
    this.inputArea.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        this.sendMessage();
      }
    });

    this.inputArea.addEventListener('input', () => {
      this.updateCharacterCounter();
      this.resizeComposer();
    });

    // A different width (window, ILIAS side bar, chat becoming visible) can change the layout
    const composer = this.container.querySelector('.ai-chat-composer');
    if (composer && typeof window.ResizeObserver === 'function') {
      let lastWidth = 0;
      new window.ResizeObserver((entries) => {
        const { width } = entries[0].contentRect;
        if (width !== lastWidth) {
          lastWidth = width;
          this.resizeComposer();
        }
      }).observe(composer);
    }

    if (this.enableChatUploads) {
      if (this.attachBtn) {
        this.attachBtn.addEventListener('click', (e) => {
          e.preventDefault();
          this.fileInput.click();
        });
      }

      if (this.fileInput) {
        this.fileInput.addEventListener('change', (e) => {
          this.handleFileSelection(e.target.files);
        });
      }

      if (this.clearAttachmentsBtn) {
        this.clearAttachmentsBtn.addEventListener('click', (e) => {
          e.preventDefault();
          this.clearAttachments();
        });
      }
    } else {
      this.hideFileUploadElements();
    }

    if (this.clearChatBtn) {
      debug('AIChatPageComponent: Clear chat button found, adding event listener');
      this.clearChatBtn.addEventListener('click', (e) => {
        debug('AIChatPageComponent: Clear chat button clicked');
        e.preventDefault();
        this.clearChatHistory();
      });
    } else {
      debug('AIChatPageComponent: Clear chat button not found with selector .ai-chat-clear-btn');
    }

    const themeToggle = this.container.querySelector('.ai-chat-theme-toggle');
    if (themeToggle) {
      themeToggle.addEventListener('click', (e) => {
        e.preventDefault();
        this.toggleTheme();
      });
    }
  }

  /**
   * Validate and send the message in the input field
   *
   * @returns {Promise<void>}
   * @throws {Error} When API request fails or response is invalid
   */
  sendMessage() {
    if (this.serviceUnavailable) {
      return;
    }

    if (this.isLoading) {
      debug('AIChatPageComponent: Request already in progress, skipping');
      return;
    }

    const message = this.inputArea.value.trim();
    if (!message) {
      this.inputArea.focus();
      return;
    }

    if (message.length > this.charLimit) {
      const errorMsg = this.container.dataset.errorMessageTooLong
                || `Message too long. Maximum ${this.charLimit} characters allowed.`;
      this.showAlert(errorMsg);
      return;
    }

    if (!this.apiUrl) {
      this.showAlert('AI service is not configured. Please contact your administrator.');
      return;
    }

    if (!this.chatId) {
      this.showAlert('Chat configuration error. Please refresh the page and try again.');
      return;
    }

    if (!this.isSessionValid()) {
      this.handleSessionExpired();
      return;
    }

    const currentAttachments = [...this.attachments];

    const welcomeMsg = this.container.querySelector('.ai-chat-welcome');
    if (welcomeMsg) {
      welcomeMsg.remove();
    }

    this.addMessageToDisplay('user', message, currentAttachments);
    this.inputArea.value = '';

    this.resizeComposer();
    this.updateCharacterCounter();

    this.clearAttachments();

    this.setLoading(true);

    if (currentAttachments.length > 0) {
      if (this.enableStreaming) {
        this.sendMessageToAIStream(message, currentAttachments);
      } else {
        this.sendMessageWithFiles(message, currentAttachments);
      }
    } else if (this.enableStreaming) {
      this.sendMessageToAIStream(message);
    } else {
      this.sendMessageToAI(message);
    }
  }

  /**
   * Send a message without streaming
   *
   * @param {string} message - The user message to send
   * @returns {Promise<void>}
   * @throws {Error} When API request fails or returns invalid response
   */
  async sendMessageToAI(message) {
    try {
      if (!this.apiUrl || this.apiUrl === '') {
        throw new Error('API URL not configured. Please ensure the plugin is properly installed.');
      }

      debug('AIChatPageComponent: Sending message to API:', message);
      debug('AIChatPageComponent: API URL:', this.apiUrl);

      this.currentRequest = new AbortController();

      const requestBody = {
        action: 'send_message',
        chat_id: this.chatId,
        message,
      };

      if (this.isAnonymous) {
        requestBody.conversation_history = this.anonymousHistory;
      }

      debug('AIChatPageComponent: About to fetch:', this.apiUrl);
      const response = await fetch(this.apiUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(requestBody),
        signal: this.currentRequest.signal,
      });

      debug('AIChatPageComponent: Response status:', response.status);
      debug('AIChatPageComponent: Response headers:', response.headers);

      if (!response.ok) {
        if (response.status === 302 || response.status === 401) {
          debug(`AIChatPageComponent: Session expired (HTTP ${response.status})`);
          this.handleSessionExpired();
          return;
        }

        const errorText = await response.text();
        debugError('HTTP Error Response:', errorText);
        let errorMessage = 'Communication with server failed';
        let serverProvided = false;
        try {
          const errorData = JSON.parse(errorText);
          if (errorData.error) {
            errorMessage = errorData.error;
            serverProvided = true;
          }
        } catch (e) {
          debugError('Response parsing failed:', e);
        }
        // Messages from the server (rate limit, access denied, ...) are shown as they are
        if (serverProvided || response.status === 429) {
          this.currentRequest = null;
          this.setLoading(false);
          if (errorMessage === 'no_service_available') {
            const notice = this.container.dataset.noServiceAvailable
                            || 'No AI service is currently available.';
            this.addMessageToDisplay('system', notice);
            this.disableInputForUnavailableService(false);
          } else {
            this.addMessageToDisplay('system', errorMessage);
          }
          return;
        }
        throw new Error(errorMessage);
      }

      const responseText = await response.text();
      debug('API Response:', responseText);

      let data;
      try {
        data = JSON.parse(responseText);
      } catch (e) {
        debugError('Failed to parse JSON:', responseText);
        throw new Error(`Invalid JSON response: ${responseText.substring(0, 100)}`);
      }

      if (!data.success) {
        throw new Error(data.error || 'Request failed');
      }

      const aiResponse = data.message;
      const sources = data.sources || null;
      const usage = data.usage || null;

      if (aiResponse) {
        if (this.isAnonymous) {
          this.anonymousHistory.push({ role: 'user', message });
          this.anonymousHistory.push({ role: 'assistant', message: aiResponse });
        }
        this.addMessageToDisplay('assistant', aiResponse, [], sources, usage);
        if (data.rag_incomplete) {
          this.showRagIncompleteNotice();
        }
      } else {
        debugError('AIChatPageComponent: Unexpected response structure:', data);
        throw new Error('No AI response received');
      }

      this.currentRequest = null;
      this.setLoading(false);
      this.saveChatHistory();
    } catch (error) {
      // Cancelled by the user
      if (error.name === 'AbortError') {
        debug('AIChatPageComponent: Request was aborted by user');
        return;
      }

      debugError('AIChatPageComponent: API request failed:', {
        error: error.message,
        apiUrl: this.apiUrl,
        chatId: this.chatId,
      });

      this.currentRequest = null;
      this.setLoading(false);

      const userMessage = this.getErrorMessage(error);
      this.addMessageToDisplay('system', userMessage);
    }
  }

  /**
   * Send a message with attachments without streaming
   *
   * @param {string} message - The user message to send
   * @param {Array<Object>} [attachments=null] - Array of attachment objects with id property
   * @returns {Promise<void>}
   * @throws {Error} When API request fails or returns invalid response
   */
  async sendMessageWithFiles(message, attachments = null) {
    try {
      if (!this.apiUrl || this.apiUrl === '') {
        throw new Error('API URL not configured. Please ensure the plugin is properly installed.');
      }

      debug('AIChatPageComponent: Sending message with files to API:', message);

      this.currentRequest = new AbortController();

      const requestBody = {
        action: 'send_message',
        chat_id: this.chatId,
        message,
        attachment_ids: (attachments || this.attachments).map((att) => att.id),
      };

      if (this.isAnonymous) {
        requestBody.conversation_history = this.anonymousHistory;
      }

      const response = await fetch(this.apiUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(requestBody),
        signal: this.currentRequest.signal,
      });

      if (!response.ok) {
        if (response.status === 302 || response.status === 401) {
          debug(`AIChatPageComponent: Session expired in file upload (HTTP ${response.status})`);
          this.handleSessionExpired();
          return;
        }

        const errorText = await response.text();
        debugError('HTTP Error Response:', errorText);
        let errorMessage = 'Communication with server failed';
        let serverProvided2 = false;
        try {
          const errorData = JSON.parse(errorText);
          if (errorData.error) {
            errorMessage = errorData.error;
            serverProvided2 = true;
          }
        } catch (e) {
          debugError('Response parsing failed:', e);
        }
        if (serverProvided2 || response.status === 429) {
          this.currentRequest = null;
          this.setLoading(false);
          if (errorMessage === 'no_service_available') {
            const notice = this.container.dataset.noServiceAvailable
                            || 'No AI service is currently available.';
            this.addMessageToDisplay('system', notice);
            this.disableInputForUnavailableService(false);
          } else {
            this.addMessageToDisplay('system', errorMessage);
          }
          return;
        }
        throw new Error(errorMessage);
      }

      const responseText = await response.text();
      debug('API Response:', responseText);

      let data;
      try {
        data = JSON.parse(responseText);
      } catch (e) {
        debugError('Failed to parse JSON:', responseText);
        throw new Error(`Invalid JSON response: ${responseText.substring(0, 100)}`);
      }

      if (!data.success) {
        throw new Error(data.error || 'Request failed');
      }

      const aiResponse = data.message;

      if (aiResponse) {
        if (this.isAnonymous) {
          this.anonymousHistory.push({ role: 'user', message });
          this.anonymousHistory.push({ role: 'assistant', message: aiResponse });
        }
        this.addMessageToDisplay('assistant', aiResponse, [], data.sources || null, data.usage || null);
        if (data.rag_incomplete) {
          this.showRagIncompleteNotice();
        }
      } else {
        debugError('AIChatPageComponent: Unexpected response structure:', data);
        throw new Error('No AI response received');
      }

      this.currentRequest = null;
      this.setLoading(false);
      this.saveChatHistory();
    } catch (error) {
      // Cancelled by the user
      if (error.name === 'AbortError') {
        debug('AIChatPageComponent: File upload request was aborted by user');
        return;
      }

      debugError('AIChatPageComponent: File upload API request failed:', {
        error: error.message,
        apiUrl: this.apiUrl,
        chatId: this.chatId,
        attachmentCount: this.attachments.length,
      });

      this.currentRequest = null;
      this.setLoading(false);

      const userMessage = this.getErrorMessage(error);
      this.addMessageToDisplay('system', userMessage);
    }
  }

  /**
   * Send a message and receive the answer as Server-Sent Events
   *
   * @param {string} message - The user message to send
   * @param {Array<Object>} [attachments=null] - Array of attachment objects with id property
   * @returns {Promise<void>}
   * @throws {Error} When streaming connection fails or receives error response
   */
  async sendMessageToAIStream(message, attachments = null) {
    try {
      if (!this.apiUrl || this.apiUrl === '') {
        throw new Error('API URL not configured. Please ensure the plugin is properly installed.');
      }

      debug('AIChatPageComponent: Starting streaming message to API:', message);

      const requestBody = {
        action: 'send_message_stream',
        chat_id: this.chatId,
        message,
      };

      if (attachments && attachments.length > 0) {
        requestBody.attachment_ids = attachments.map((att) => att.id);
      }

      if (this.isAnonymous) {
        requestBody.conversation_history = this.anonymousHistory;
      }

      // EventSource only supports GET, so the data is sent as query parameters
      const params = new URLSearchParams();
      params.append('action', requestBody.action);
      params.append('chat_id', requestBody.chat_id);
      params.append('message', requestBody.message);
      if (requestBody.attachment_ids) {
        params.append('attachment_ids', JSON.stringify(requestBody.attachment_ids));
      }
      if (requestBody.conversation_history) {
        params.append('conversation_history', JSON.stringify(requestBody.conversation_history));
      }

      const eventSource = new window.EventSource(`${this.apiUrl}?${params.toString()}`);

      this.currentEventSource = eventSource;

      const messageElement = this.createStreamingMessageElement();
      let streamedContent = '';

      eventSource.onmessage = (event) => {
        try {
          const data = JSON.parse(event.data);
          debug('AIChatPageComponent: Streaming data received:', data);

          if (data.type === 'start') {
            debug('AIChatPageComponent: Streaming started');
          } else if (data.type === 'sources') {
            this.showStreamingSources(messageElement, data.sources);
          } else if (data.type === 'complete') {
            debug('AIChatPageComponent: Streaming completed');
            const sources = data.sources || null;
            const usage = data.usage || null;
            const finalContent = data.message || streamedContent;
            if (this.isAnonymous && finalContent) {
              this.anonymousHistory.push({ role: 'user', message });
              this.anonymousHistory.push({ role: 'assistant', message: finalContent });
            }
            this.finalizeStreamedMessage(messageElement, finalContent, sources, usage);
            if (data.rag_incomplete) {
              this.showRagIncompleteNotice();
            }
            eventSource.close();
            this.currentEventSource = null;
            this.setLoading(false);
            this.saveChatHistory();
          } else if (data.error || data.type === 'error') {
            // Error from the server: cleanupStreaming() closes the stream and resets the UI;
            // currentEventSource must not be reset before, otherwise the cleanup is skipped
            const serverError = data.error || 'An error occurred.';
            this.cleanupStreaming(false);
            if (serverError === 'no_service_available') {
              const notice = this.container.dataset.noServiceAvailable
                                || 'No AI service is currently available.';
              this.addMessageToDisplay('system', notice);
              this.disableInputForUnavailableService(false);
            } else {
              this.addMessageToDisplay('system', serverError);
            }
          } else if (data.type === 'chunk' && data.content) {
            const chunk = data.content;
            streamedContent += chunk;
            this.appendToStreamingMessage(messageElement, chunk);
          }
        } catch (error) {
          debugError('AIChatPageComponent: Error parsing streaming data:', {
            error: error.message,
            eventData: event.data,
            chatId: this.chatId,
          });

          this.cleanupStreaming(false);
          this.addMessageToDisplay('system', 'Streaming error: Unable to process AI response. Please try again.');
        }
      };

      eventSource.onerror = (error) => {
        debugError('AIChatPageComponent: EventSource error:', error);

        this.cleanupStreaming(false);

        // Connection errors of EventSource are often caused by an expired session
        if (!this.isSessionValid()) {
          this.handleSessionExpired();
        } else {
          this.addMessageToDisplay('system', 'Streaming connection error. Please try again.');
        }
      };
    } catch (error) {
      debugError('AIChatPageComponent: Streaming setup failed:', {
        error: error.message,
        apiUrl: this.apiUrl,
        chatId: this.chatId,
      });
      this.setLoading(false);

      const userMessage = this.getErrorMessage(error);
      this.addMessageToDisplay('system', userMessage);
    }
  }

  /**
   * Create the message element that receives the streamed answer
   *
   * @returns {{messageEl: HTMLElement, contentEl: HTMLElement}} References to message and content elements
   */
  createStreamingMessageElement() {
    this.streamState = AIChatPageComponent.createStreamState();

    // The placeholder with the thinking indicator becomes the answer
    if (this.pendingMessage) {
      const pending = this.pendingMessage;
      this.pendingMessage = null;
      pending.messageEl.className = 'ai-chat-message assistant streaming';
      return pending;
    }

    const messageEl = document.createElement('div');
    messageEl.className = 'ai-chat-message assistant streaming';

    const contentEl = document.createElement('div');
    contentEl.className = 'ai-chat-message-content';
    contentEl.appendChild(this.createThinkingIndicator());

    messageEl.appendChild(contentEl);
    this.messagesArea.appendChild(messageEl);
    this.scrollToBottom();

    return { messageEl, contentEl };
  }

  /**
   * Append a streamed text fragment
   *
   * @param {{messageEl: HTMLElement, contentEl: HTMLElement}} messageElement - Message element references
   * @param {string} chunk - New text content to append
   */
  appendToStreamingMessage(messageElement, chunk) {
    const { contentEl } = messageElement;

    if (!contentEl.dataset.rawContent) {
      contentEl.dataset.rawContent = '';
    }
    contentEl.dataset.rawContent += chunk;

    // Markdown is rendered at most once per frame; the answer may be finalized or
    // stopped before the frame is drawn
    const state = this.streamState;
    if (state.renderPending) {
      return;
    }
    state.renderPending = true;
    window.requestAnimationFrame(() => {
      state.renderPending = false;
      if (!messageElement.messageEl.classList.contains('streaming')) {
        return;
      }
      // RAG citations [cit-N] (0-based) are shown as [N+1], as in the final message
      const rawContent = (contentEl.dataset.rawContent || '')
        .replace(/\[cit-(\d+)\]/gi, (m, n) => `[${parseInt(n, 10) + 1}]`);
      contentEl.innerHTML = this.renderMarkdown(rawContent);
      this.decorateRenderedContent(contentEl);
      if (state.sourcesRow) {
        // Without tooltips: the chips are replaced with every frame
        this.convertFootnotesToChips(contentEl, state.sourcesRow, state.sources, false);
      }
      AIChatPageComponent.appendStreamingCursor(contentEl);
      this.scrollToBottom();
    });
  }

  /**
   * Show the sources of a streamed RAG answer, which arrive before the answer
   *
   * @param {{messageEl: HTMLElement, contentEl: HTMLElement}} messageElement - Message element references
   * @param {Array|null} sources
   */
  showStreamingSources(messageElement, sources) {
    if (!Array.isArray(sources) || sources.length === 0) {
      return;
    }
    const state = this.streamState;
    if (state.sourcesRow) {
      state.sourcesRow.remove();
    }
    state.sources = sources;
    state.sourcesRow = this.renderSourcesRow(sources);
    messageElement.messageEl.appendChild(state.sourcesRow);
  }

  /**
   * Show the streaming cursor at the end of the last text block
   *
   * @param {HTMLElement} contentEl
   */
  static appendStreamingCursor(contentEl) {
    const cursor = document.createElement('span');
    cursor.className = 'streaming-cursor';
    cursor.setAttribute('aria-hidden', 'true');

    let target = contentEl.lastElementChild;
    while (target && ['UL', 'OL', 'BLOCKQUOTE'].includes(target.tagName) && target.lastElementChild) {
      target = target.lastElementChild;
    }
    if (target && ['P', 'LI', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6'].includes(target.tagName)) {
      target.appendChild(cursor);
    } else {
      contentEl.appendChild(cursor);
    }
  }

  /**
   * Render the complete answer with Markdown, sources and actions
   *
   * @param {{messageEl: HTMLElement, contentEl: HTMLElement}} messageElement - Message element references
   * @param {string} finalContent - Complete message content for formatting
   * @param {Array|null} sources - RAG source citations if available
   * @param {Object|null} usage - Token usage data if available
   */
  finalizeStreamedMessage(messageElement, finalContent, sources = null, usage = null) {
    const { messageEl, contentEl } = messageElement;

    messageEl.classList.remove('streaming');
    const cursor = contentEl.querySelector('.streaming-cursor');
    if (cursor) {
      cursor.remove();
    }

    // The complete answer from the server is preferred over the streamed chunks,
    // because the server may have removed citations
    let contentToFormat = finalContent || contentEl.dataset.rawContent;

    let effectiveSources = sources;
    if (sources && sources.length > 0) {
      const { text: stripped, webLinks } = this.stripInlineSourcesWithLinks(contentToFormat, sources);
      contentToFormat = stripped;
      if (webLinks.length > 0) {
        effectiveSources = [...sources, ...webLinks];
      }
    }

    contentEl.innerHTML = this.formatMessage(contentToFormat);
    this.decorateRenderedContent(contentEl);

    delete contentEl.dataset.rawContent;

    // The sources of the complete answer replace those shown while streaming
    if (this.streamState.sourcesRow) {
      this.streamState.sourcesRow.remove();
      this.streamState.sourcesRow = null;
    }

    if (effectiveSources && effectiveSources.length > 0) {
      const sourcesRow = this.renderSourcesRow(effectiveSources);
      messageEl.appendChild(sourcesRow);
      // Chips use the original source indices
      this.convertFootnotesToChips(contentEl, sourcesRow, effectiveSources);
    }

    const actionsEl = this.createMessageActions();

    if (this.isAdmin) {
      const rawBtn = document.createElement('button');
      rawBtn.className = 'ai-chat-message-action ai-chat-raw-btn';
      rawBtn.title = 'Raw data (Admin)';
      rawBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" focusable="false"><path d="M5.854 4.854a.5.5 0 1 0-.708-.708l-3.5 3.5a.5.5 0 0 0 0 .708l3.5 3.5a.5.5 0 0 0 .708-.708L2.707 8l3.147-3.146zm4.292 0a.5.5 0 0 1 .708-.708l3.5 3.5a.5.5 0 0 1 0 .708l-3.5 3.5a.5.5 0 0 1-.708-.708L13.293 8l-3.147-3.146z"/></svg>';
      rawBtn.addEventListener('click', (e) => {
        e.preventDefault();
        this.toggleRawPanel(messageEl, finalContent, effectiveSources, usage);
      });
      actionsEl.appendChild(rawBtn);
    }

    messageEl.appendChild(actionsEl);

    this.messageHistory.push({
      role: 'assistant',
      content: contentToFormat,
      timestamp: Date.now(),
      sources,
      usage,
    });

    this.scrollToBottom();
  }

  /**
   * Stop current streaming operation if active
   */
  stopStreaming() {
    this.cleanupStreaming(true);
  }

  /**
   * Clean up streaming state and UI elements
   *
   * @param {boolean} userStopped - Whether the user manually stopped streaming
   */
  cleanupStreaming(userStopped = false) {
    if (this.currentEventSource) {
      this.currentEventSource.close();
      this.currentEventSource = null;
      this.setLoading(false);

      const streamingMessages = this.messagesArea.querySelectorAll('.ai-chat-message.streaming');
      streamingMessages.forEach((msg) => {
        msg.classList.remove('streaming');
        const contentEl = msg.querySelector('.ai-chat-message-content');
        if (contentEl) {
          contentEl.querySelectorAll('.streaming-cursor, .ai-chat-thinking').forEach((element) => element.remove());

          if (userStopped) {
            contentEl.innerHTML += '<em class="generation-stopped"> [Generation stopped by user]</em>';
          } else if (contentEl.textContent.trim() === '') {
            // Error before any text: no empty answer
            msg.remove();
          }
        }
      });
    }
  }

  /**
   * Add a message to both the display and message history
   *
   * @param {string} role - Message role ('user', 'assistant', or 'system')
   * @param {string} content - Message content text
   * @param {Array<Object>} [attachments=[]] - Array of attachment objects to display
   * @param {Array<Object>} [sources=null] - Array of RAG source citations
   * @param {Object} [usage=null] - Token usage data
   */
  addMessageToDisplay(role, content, attachments = [], sources = null, usage = null) {
    this.displayMessageOnly(role, content, attachments, sources, usage);

    this.messageHistory.push({
      role,
      content,
      timestamp: Date.now(),
      sources,
      usage,
    });

    if (this.messageHistory.length > this.maxMemory * 2) {
      this.messageHistory = this.messageHistory.slice(-this.maxMemory * 2);
    }
  }

  /**
   * Display a message in the chat area without adding to history
   *
   * @param {string} role - Message role ('user', 'assistant', or 'system')
   * @param {string} content - Message content text
   * @param {Array<Object>} [attachments=[]] - Array of attachment objects to display
   * @param {Array<Object>} [sources=null] - Array of RAG source citations
   * @param {Object} [usage=null] - Token usage data
   */
  displayMessageOnly(role, content, attachments = [], sources = null, usage = null) {
    debug('AIChatPageComponent: displayMessageOnly called with attachments:', attachments, 'sources:', sources);

    // An answer or error message replaces the thinking indicator
    if (role !== 'user') {
      this.removeThinkingPlaceholder();
    }

    if (this.welcomeMsg && this.welcomeMsg.parentNode) {
      this.welcomeMsg.remove();
    }

    const messageDiv = document.createElement('div');
    messageDiv.className = `ai-chat-message ${role}`;

    const contentWrapper = document.createElement('div');
    contentWrapper.className = 'ai-chat-message-content';

    if (role === 'assistant') {
      let displayContent = content;
      let effectiveSources = sources;
      if (sources && sources.length > 0) {
        const { text: stripped, webLinks } = this.stripInlineSourcesWithLinks(content, sources);
        displayContent = stripped;
        if (webLinks.length > 0) effectiveSources = [...sources, ...webLinks];
      }
      contentWrapper.innerHTML = this.renderMarkdown(displayContent);
      this.decorateRenderedContent(contentWrapper);
      sources = effectiveSources;
    } else {
      contentWrapper.textContent = content;
    }

    if (attachments && attachments.length > 0) {
      const attachmentsDiv = document.createElement('div');
      attachmentsDiv.className = 'ai-chat-message-attachments';

      attachments.forEach((attachment) => {
        debug('AIChatPageComponent: Processing attachment for display:', attachment);

        if (attachment.is_image || attachment.file_type === 'image') {
          this.createImageAttachment(attachment, attachmentsDiv);
        } else if (attachment.file_type === 'pdf') {
          this.createPdfAttachment(attachment, attachmentsDiv);
        } else if (attachment.file_type === 'document') {
          this.createDocumentAttachment(attachment, attachmentsDiv);
        } else if (attachment.download_url) {
          this.createGenericAttachment(attachment, attachmentsDiv);
        }
        this.appendRagStatusBadge(attachmentsDiv, attachment);
      });

      if (role === 'user') {
        messageDiv.appendChild(attachmentsDiv);
        messageDiv.appendChild(contentWrapper);
      } else {
        contentWrapper.appendChild(attachmentsDiv);
        messageDiv.appendChild(contentWrapper);
      }
    } else {
      messageDiv.appendChild(contentWrapper);
    }

    if (role === 'assistant') {
      let sourcesRow = null;
      if (sources && sources.length > 0) {
        sourcesRow = this.renderSourcesRow(sources);
        messageDiv.appendChild(sourcesRow);

        this.convertFootnotesToChips(contentWrapper, sourcesRow, sources);
      }

      const actionsDiv = document.createElement('div');
      actionsDiv.className = 'ai-chat-message-actions';

      const copyBtn = document.createElement('button');
      copyBtn.className = 'ai-chat-message-action';
      copyBtn.title = this.lang.copyMessageTitle;
      copyBtn.innerHTML = `
                <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" focusable="false">
                    <path d="M4 1.5H3a2 2 0 0 0-2 2V14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V3.5a2 2 0 0 0-2-2h-1v1h1a1 1 0 0 1 1 1V14a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V3.5a1 1 0 0 1 1-1h1v-1z"/>
                    <path d="M9.5 1a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-.5.5h-3a.5.5 0 0 1-.5-.5v-1a.5.5 0 0 1 .5-.5h3zm-3-1A1.5 1.5 0 0 0 5 1.5v1A1.5 1.5 0 0 0 6.5 4h3A1.5 1.5 0 0 0 11 2.5v-1A1.5 1.5 0 0 0 9.5 0h-3z"/>
                </svg>
            `;
      copyBtn.addEventListener('click', (e) => {
        e.preventDefault();
        this.copyMessageToClipboard(content, copyBtn);
      });

      const regenBtn = document.createElement('button');
      regenBtn.className = 'ai-chat-message-action';
      regenBtn.title = this.lang.regenerateResponseTitle;
      regenBtn.innerHTML = `
                <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" focusable="false">
                    <path fill-rule="evenodd" d="M8 3a5 5 0 1 0 4.546 2.914.5.5 0 0 1 .908-.417A6 6 0 1 1 8 2v1z"/>
                    <path d="M8 4.466V.534a.25.25 0 0 1 .41-.192l2.36 1.966c.12.1.12.284 0 .384L8.41 4.658A.25.25 0 0 1 8 4.466z"/>
                </svg>
            `;
      regenBtn.addEventListener('click', (e) => {
        e.preventDefault();
        this.regenerateResponse(messageDiv);
      });

      actionsDiv.appendChild(copyBtn);
      actionsDiv.appendChild(regenBtn);

      if (this.isAdmin) {
        const rawBtn = document.createElement('button');
        rawBtn.className = 'ai-chat-message-action ai-chat-raw-btn';
        rawBtn.title = 'Raw data (Admin)';
        rawBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" focusable="false"><path d="M5.854 4.854a.5.5 0 1 0-.708-.708l-3.5 3.5a.5.5 0 0 0 0 .708l3.5 3.5a.5.5 0 0 0 .708-.708L2.707 8l3.147-3.146zm4.292 0a.5.5 0 0 1 .708-.708l3.5 3.5a.5.5 0 0 1 0 .708l-3.5 3.5a.5.5 0 0 1-.708-.708L13.293 8l-3.147-3.146z"/></svg>';
        rawBtn.addEventListener('click', (e) => {
          e.preventDefault();
          this.toggleRawPanel(messageDiv, content, sources, usage);
        });
        actionsDiv.appendChild(rawBtn);
      }

      messageDiv.appendChild(actionsDiv);
    }

    this.messagesArea.appendChild(messageDiv);
    this.scrollToBottom(true);

    // Announce new answers to screen readers
    if (role === 'assistant') {
      this.announceToScreenReader(this.lang.newMessageAria);
    }
  }

  /**
   * Render markdown text to HTML with AI-specific formatting
   *
   * @param {string} text - Raw markdown text to render
   * @returns {string} HTML-formatted string
   */
  renderMarkdown(text) {
    if (!text) return '';

    // Answers and RAG excerpts may contain HTML injected via documents
    if (typeof window.DOMPurify === 'undefined' || !window.DOMPurify.isSupported) {
      return `<p class="ai-chat-paragraph">${this.escapeHtml(text).replace(/\n/g, '<br>')}</p>`;
    }

    if (typeof marked !== 'undefined') {
      window.marked.setOptions({
        breaks: true,
        gfm: true,
        sanitize: false,
        smartypants: false,
      });

      configureAIChatMarked();
      const renderer = new window.marked.Renderer();

      // In CommonMark a closing ** after punctuation (e.g. ")") must be followed by
      // whitespace or punctuation. Superscript digits are neither, so ")**¹" would not
      // close the bold text; a space is inserted between ** and the superscript.
      text = text.replace(/(\S)\*\*([¹²³⁴⁵⁶⁷⁸⁹⁰])/g, '$1** $2');

      return this.sanitizeHtml(window.marked.parse(text, { renderer }));
    }

    // Fallback if marked.js is not loaded
    return this.sanitizeHtml(this.renderMarkdownFallback(text));
  }

  /**
   * Remove scripts, event handlers, unsafe URLs and form elements from rendered HTML
   *
   * Remote images are replaced by links, so that an answer cannot send data
   * to other servers by loading an image. Links open in a new tab.
   *
   * @param {string} html - Rendered HTML
   * @returns {string} Cleaned HTML
   */
  sanitizeHtml(html) {
    const fragment = window.DOMPurify.sanitize(html, {
      RETURN_DOM_FRAGMENT: true,
      FORBID_TAGS: ['style', 'form', 'button', 'textarea', 'select'],
      FORBID_ATTR: ['style', 'id', 'name'],
      ALLOWED_URI_REGEXP: AIChatPageComponent.SAFE_URL,
    });

    fragment.querySelectorAll('input').forEach((input) => {
      if (input.getAttribute('type') === 'checkbox') {
        input.setAttribute('disabled', '');
      } else {
        input.remove();
      }
    });

    fragment.querySelectorAll('img').forEach((img) => {
      const src = img.getAttribute('src') || '';
      if (src.startsWith('data:image/')) {
        return;
      }
      const isRemote = /^https?:/i.test(src);
      const label = img.getAttribute('alt') || (isRemote ? src : '');
      if (isRemote) {
        const link = document.createElement('a');
        link.setAttribute('href', src);
        link.textContent = label;
        img.replaceWith(link);
      } else {
        img.replaceWith(document.createTextNode(label));
      }
    });

    fragment.querySelectorAll('a[href]').forEach((link) => {
      link.setAttribute('target', '_blank');
      link.setAttribute('rel', 'noopener noreferrer');
    });

    fragment.querySelectorAll('blockquote').forEach((quote) => this.convertAlert(quote));

    const container = document.createElement('div');
    container.appendChild(fragment);
    return container.innerHTML;
  }

  /**
   * Convert a blockquote starting with [!NOTE], [!TIP], [!IMPORTANT], [!WARNING]
   * or [!CAUTION] (GitHub notation) into a highlighted box
   *
   * @param {HTMLElement} quote
   */
  convertAlert(quote) {
    const paragraph = quote.firstElementChild;
    if (!paragraph || paragraph.tagName !== 'P' || !paragraph.firstChild
        || paragraph.firstChild.nodeType !== window.Node.TEXT_NODE) {
      return;
    }

    const marker = paragraph.firstChild;
    const match = /^\s*\[!(NOTE|TIP|IMPORTANT|WARNING|CAUTION)\]\s*/i.exec(marker.textContent);
    if (!match) {
      return;
    }

    const type = match[1].toLowerCase();
    marker.textContent = marker.textContent.slice(match[0].length);
    if (marker.textContent === '') {
      if (marker.nextSibling && marker.nextSibling.nodeName === 'BR') {
        marker.nextSibling.remove();
      }
      marker.remove();
    }
    if (!paragraph.firstChild) {
      paragraph.remove();
    }

    const title = document.createElement('div');
    title.className = 'ai-chat-alert-title';
    title.textContent = this.lang.alerts[type];
    quote.classList.add('ai-chat-alert', `ai-chat-alert-${type}`);
    quote.insertBefore(title, quote.firstChild);
  }

  /**
   * Add toolbars to tables and code blocks, highlight code and render formulas
   *
   * highlight.js and KaTeX are loaded when an answer contains code or formulas.
   *
   * @param {HTMLElement} contentEl - Element with the rendered answer
   */
  decorateRenderedContent(contentEl) {
    contentEl.querySelectorAll('table').forEach((table) => {
      if (table.closest('.ai-chat-table-wrap')) {
        return;
      }
      const wrap = document.createElement('div');
      wrap.className = 'ai-chat-table-wrap';
      const toolbar = document.createElement('div');
      toolbar.className = 'ai-chat-table-toolbar';
      toolbar.appendChild(AIChatPageComponent.createContentButton('ai-chat-table-copy', this.lang.tableCopy, AIChatPageComponent.ICON_COPY));
      toolbar.appendChild(AIChatPageComponent.createContentButton('ai-chat-table-csv', this.lang.tableExportCsv, AIChatPageComponent.ICON_DOWNLOAD));
      const scroll = document.createElement('div');
      scroll.className = 'ai-chat-table-scroll';
      table.replaceWith(wrap);
      scroll.appendChild(table);
      wrap.append(toolbar, scroll);
    });

    const codeBlocks = [];
    contentEl.querySelectorAll('pre > code').forEach((code) => {
      const pre = code.parentElement;
      if (pre.closest('.ai-chat-code-block')) {
        return;
      }
      const match = /(?:^|\s)language-([\w+#.-]+)/.exec(code.className);
      const language = match ? match[1] : '';

      const block = document.createElement('div');
      block.className = 'ai-chat-code-block';
      const header = document.createElement('div');
      header.className = 'ai-chat-code-header';
      const label = document.createElement('span');
      label.className = 'ai-chat-code-language';
      label.textContent = language || 'text';
      header.append(label, AIChatPageComponent.createContentButton('ai-chat-code-copy', this.lang.codeCopy, AIChatPageComponent.ICON_COPY));
      pre.classList.add('ai-chat-code-content');
      pre.replaceWith(block);
      block.append(header, pre);

      if (language) {
        codeBlocks.push({ code, language });
      }
    });

    if (codeBlocks.length > 0) {
      loadAIChatVendor('highlight.min.js', 'hljs').then(() => {
        codeBlocks.forEach(({ code, language }) => {
          if (window.hljs.getLanguage(language) && !code.dataset.highlighted) {
            window.hljs.highlightElement(code);
          }
        });
      }).catch((error) => debugError('AIChatPageComponent: highlight.js not available', error));
    }

    const formulas = [...contentEl.querySelectorAll('.ai-chat-math:not(.ai-chat-math-rendered)')];
    if (formulas.length > 0) {
      // Without the stylesheet, formulas are still rendered (MathML)
      const stylesheet = loadAIChatVendor('katex/katex.min.css').catch(() => {});
      Promise.all([stylesheet, loadAIChatVendor('katex/katex.min.js', 'katex')]).then(() => {
        formulas.forEach((element) => {
          try {
            // Formulas are rendered again with every frame while streaming
            const displayMode = element.dataset.display === '1';
            const key = `${displayMode ? 1 : 0}:${element.textContent}`;
            let html = aiChatFormulaCache.get(key);
            if (html === undefined) {
              html = window.katex.renderToString(element.textContent, {
                displayMode,
                throwOnError: false,
                trust: false,
              });
              if (aiChatFormulaCache.size >= 500) {
                aiChatFormulaCache.clear();
              }
              aiChatFormulaCache.set(key, html);
            }
            element.replaceChildren();
            element.insertAdjacentHTML('beforeend', html);
            element.classList.add('ai-chat-math-rendered');
          } catch (error) {
            debugError('AIChatPageComponent: formula not rendered', error);
          }
        });
      }).catch((error) => debugError('AIChatPageComponent: KaTeX not available', error));
    }
  }

  /**
   * Icon button for tables and code blocks
   *
   * @param {string} className
   * @param {string} label
   * @param {string} icon - SVG markup
   * @returns {HTMLButtonElement}
   */
  static createContentButton(className, label, icon) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = `ai-chat-content-action ${className}`;
    button.title = label;
    button.setAttribute('aria-label', label);
    button.innerHTML = icon;
    return button;
  }

  /**
   * Cell texts of a table, header included
   *
   * @param {HTMLTableElement} table
   * @returns {string[][]}
   */
  static tableToRows(table) {
    return [...table.rows].map((row) => [...row.cells].map((cell) => cell.textContent.replace(/\s+/g, ' ').trim()));
  }

  /**
   * Download a table as CSV (UTF-8 with byte order mark, so that spreadsheet
   * programs detect the encoding)
   *
   * Cells starting with =, +, - or @ are prefixed with ' so that spreadsheet
   * programs do not execute them as formulas.
   *
   * @param {HTMLTableElement} table
   */
  static downloadTableAsCsv(table) {
    const csv = AIChatPageComponent.tableToRows(table).map((row) => row.map((cell) => {
      const value = /^[=+\-@\t\r]/.test(cell) ? `'${cell}` : cell;
      return `"${value.replace(/"/g, '""')}"`;
    }).join(',')).join('\r\n');

    const url = URL.createObjectURL(new Blob([`\uFEFF${csv}`], { type: 'text/csv;charset=utf-8' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = 'table.csv';
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  }

  /**
   * URL that may be used in a link: http, https, mailto or relative
   *
   * @param {string} url
   * @returns {string} The URL, or an empty string if it is not allowed
   */
  safeUrl(url) {
    return url && AIChatPageComponent.SAFE_URL.test(url) ? url : '';
  }

  /**
   * Fallback markdown rendering when marked.js is not available
   *
   * @param {string} text - Raw markdown text to render
   * @returns {string} HTML-formatted string
   */
  renderMarkdownFallback(text) {
    let html = this.escapeHtml(text);

    html = this.renderCodeBlocks(html);
    html = this.renderInlineCode(html);
    html = this.renderHeaders(html);
    html = this.renderBold(html);
    html = this.renderItalic(html);
    html = this.renderLinks(html);
    html = this.renderLists(html);
    html = this.renderTables(html);
    html = this.renderBlockquotes(html);
    html = this.renderMistralSpecialFormatting(html);
    html = this.renderLineBreaks(html);

    return html;
  }

  /**
   * Escape HTML special characters to prevent XSS attacks
   *
   * @param {string} text - Raw text that may contain HTML characters
   * @returns {string} HTML-escaped text safe for display
   */
  escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  /**
   * Render fenced code blocks with language label and copy button
   *
   * @param {string} text - Text containing code block markdown
   * @returns {string} Text with code blocks converted to HTML
   */
  renderCodeBlocks(text) {
    text = text.replace(/```(\w+)?\n([\s\S]*?)\n```/g, (match, language, code) => {
      const lang = language || 'text';
      return `<div class="ai-chat-code-block">
                <div class="ai-chat-code-header">
                    <span class="ai-chat-code-language">${lang}</span>
                    <button type="button" class="ai-chat-code-copy" title="${this.escapeHtml(this.lang.codeCopy)}">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false">
                            <path d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"/>
                        </svg>
                    </button>
                </div>
                <pre class="ai-chat-code-content language-${lang}"><code>${code}</code></pre>
            </div>`;
    });

    return text;
  }

  renderInlineCode(text) {
    return text.replace(/`([^`]+)`/g, '<code class="ai-chat-inline-code">$1</code>');
  }

  renderHeaders(text) {
    text = text.replace(/^###### (.*)$/gm, '<h6>$1</h6>');
    text = text.replace(/^##### (.*)$/gm, '<h5>$1</h5>');
    text = text.replace(/^#### (.*)$/gm, '<h4>$1</h4>');
    text = text.replace(/^### (.*)$/gm, '<h3>$1</h3>');
    text = text.replace(/^## (.*)$/gm, '<h2>$1</h2>');
    text = text.replace(/^# (.*)$/gm, '<h1>$1</h1>');
    return text;
  }

  renderBold(text) {
    text = text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
    text = text.replace(/__(.*?)__/g, '<strong>$1</strong>');
    return text;
  }

  renderItalic(text) {
    text = text.replace(/(?<!\*)\*([^*]+)\*(?!\*)/g, '<em>$1</em>');
    text = text.replace(/(?<!_)_([^_]+)_(?!_)/g, '<em>$1</em>');
    return text;
  }

  renderLinks(text) {
    text = text.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" class="ai-chat-link" target="_blank" rel="noopener noreferrer">$1</a>');

    text = text.replace(/(https?:\/\/[^\s<>"]+)/g, '<a href="$1" class="ai-chat-link" target="_blank" rel="noopener noreferrer">$1</a>');

    return text;
  }

  renderLists(text) {
    text = text.replace(/^\d+\.\s+(.+)$/gm, '___ORDERED___<li class="ai-chat-list-item">$1</li>');

    const orderedListRegex = /(___ORDERED___<li class="ai-chat-list-item">.*?<\/li>(?:\s*___ORDERED___<li class="ai-chat-list-item">.*?<\/li>)*)/gs;
    text = text.replace(orderedListRegex, (match) => {
      const cleanMatch = match.replace(/___ORDERED___/g, '');
      return `<ol class="ai-chat-ordered-list">${cleanMatch}</ol>`;
    });

    text = text.replace(/___ORDERED___/g, '');

    text = text.replace(/^[-*+]\s+(.+)$/gm, '<li class="ai-chat-list-item">$1</li>');

    text = text.replace(
      /(<li class="ai-chat-list-item">.*?<\/li>(?:\s*<li class="ai-chat-list-item">.*?<\/li>)*)/gs,
      '<ul class="ai-chat-list">$1</ul>',
    );

    return text;
  }

  renderTables(text) {
    return text.replace(/^\|(.+)\|\s*\n\|[-\s:|]+\|\s*\n(\|.+\|\s*(?:\n|$))+/gm, (match) => {
      const lines = match.trim().split('\n');
      let html = '<table class="ai-chat-table">';

      if (lines[0]) {
        const headerCells = lines[0].split('|').slice(1, -1);
        html += '<thead><tr class="ai-chat-table-header-row">';
        headerCells.forEach((cell) => {
          html += `<th class="ai-chat-table-header">${cell.trim()}</th>`;
        });
        html += '</tr></thead>';
      }

      html += '<tbody>';
      for (let i = 2; i < lines.length; i++) {
        if (!lines[i].trim()) continue;
        const cells = lines[i].split('|').slice(1, -1);
        html += '<tr class="ai-chat-table-row">';
        cells.forEach((cell) => {
          html += `<td class="ai-chat-table-cell">${cell.trim()}</td>`;
        });
        html += '</tr>';
      }
      html += '</tbody></table>';

      return html;
    });
  }

  renderBlockquotes(text) {
    return text.replace(/^>\s*(.+(?:\n>\s*.+)*)/gm, (match) => {
      const content = match.replace(/^>\s*/gm, '');
      return `<div class="ai-chat-blockquote">${content}</div>`;
    });
  }

  /**
   * Thinking blocks (<thinking>…</thinking>) in the escaped text of the fallback renderer
   *
   * @param {string} text - HTML-escaped text
   * @returns {string}
   */
  renderMistralSpecialFormatting(text) {
    return text.replace(
      /&lt;thinking&gt;([\s\S]*?)&lt;\/thinking&gt;/g,
      `<div class="ai-chat-thinking-block"><div class="ai-chat-thinking-header">${this.lang.thinkingHeader}</div><div class="ai-chat-thinking-content">$1</div></div>`,
    );
  }

  renderLineBreaks(text) {
    const paragraphs = text.split(/\n\s*\n/);
    let html = '';

    paragraphs.forEach((paragraph) => {
      paragraph = paragraph.trim();
      if (!paragraph) return;

      // Formatted blocks are not wrapped in paragraphs
      if (paragraph.match(/^<(div|ul|ol|table|blockquote|h[1-6])/)) {
        html += `${paragraph}\n`;
      } else {
        paragraph = paragraph.replace(/\n/g, '<br>');
        html += `<p class="ai-chat-paragraph">${paragraph}</p>\n`;
      }
    });

    return html;
  }

  /**
   * Toggle the raw data panel (administrators only)
   */
  toggleRawPanel(messageEl, rawText, sources, usage) {
    let panel = messageEl.querySelector('.ai-chat-raw-panel');
    if (panel) {
      panel.remove();
      return;
    }

    const data = {
      message: rawText || '',
      sources: sources || [],
      usage: usage || {},
    };

    panel = document.createElement('details');
    panel.className = 'ai-chat-raw-panel';
    panel.open = true;
    panel.innerHTML = `<summary>Raw Data</summary><pre class="ai-chat-raw-pre">${this.escapeHtml(JSON.stringify(data, null, 2))}</pre>`;
    messageEl.appendChild(panel);
  }

  /**
   * Set side and width of the unfolding file name of a source chip
   *
   * The name unfolds as an overlay, so the chip keeps its size in the text and
   * does not wrap. It unfolds towards the side with more space within the answer
   * and scrolls if it is wider than the available space.
   *
   * @param {HTMLElement} chip
   */
  prepareChipUnfold(chip) {
    const bounds = (chip.closest('.ai-chat-message-content') || this.container).getBoundingClientRect();
    const rect = chip.getBoundingClientRect();
    const spaceRight = bounds.right - rect.right;
    const spaceLeft = rect.left - bounds.left;
    const toLeft = spaceRight < AIChatPageComponent.CHIP_NAME_WIDTH && spaceLeft > spaceRight;
    const space = (toLeft ? spaceLeft : spaceRight) - 4;
    const width = Math.max(0, Math.min(AIChatPageComponent.CHIP_NAME_WIDTH, space));
    chip.classList.toggle('ai-chat-chip-unfold-left', toLeft);
    chip.style.setProperty('--chip-name-width', `${width}px`);

    // Horizontal padding of the unfolded name (see CSS)
    const textWidth = width - 14;
    const overflow = chip.querySelector('.ai-chat-chip-text').scrollWidth - textWidth;
    chip.classList.toggle('ai-chat-chip-scrollable', overflow > 0);
    chip.style.setProperty('--chip-scroll-offset', `-${Math.max(0, overflow)}px`);
  }

  /**
   * Tooltip of a source chip with file name, pages and excerpt
   *
   * Opens on mouse hover and keyboard focus, not on touch. Width and height do
   * not depend on the shown source: several sources are shown one at a time
   * with buttons and the arrow keys to switch between them, at the height of
   * the longest one. The tooltip closes on scrolling, resizing, clicking the
   * chip and clicking elsewhere, because it has a fixed position.
   *
   * @param {HTMLElement} element
   * @param {Object[]} sourceList - Sources cited by the chip
   */
  addSourceInfoTooltip(element, sourceList) {
    let tooltip = null;
    let closeTimer = null;
    let current = 0;

    const cancelClose = () => {
      if (closeTimer) { clearTimeout(closeTimer); closeTimer = null; }
    };

    let onOutsidePointer = null;
    let onScroll = null;

    const close = () => {
      cancelClose();
      if (tooltip) { tooltip.remove(); tooltip = null; }
      window.removeEventListener('scroll', onScroll, true);
      window.removeEventListener('resize', close);
      document.removeEventListener('pointerdown', onOutsidePointer, true);
    };

    onOutsidePointer = (e) => {
      if (tooltip && !tooltip.contains(e.target) && !element.contains(e.target)) {
        close();
      }
    };

    // Scrolling the page moves the chip away; scrolling inside the tooltip does not
    onScroll = (e) => {
      if (tooltip && !tooltip.contains(e.target)) {
        close();
      }
    };

    const scheduleClose = () => {
      cancelClose();
      closeTimer = setTimeout(close, 120);
    };

    const position = () => {
      const rect = element.getBoundingClientRect();
      const tip = tooltip.getBoundingClientRect();
      const margin = 16;
      const left = Math.max(margin, Math.min(rect.left, window.innerWidth - tip.width - margin));
      let top = rect.bottom + 6;
      if (top + tip.height > window.innerHeight - margin && rect.top - tip.height - 6 >= margin) {
        top = rect.top - tip.height - 6;
      }
      tooltip.style.left = `${left}px`;
      tooltip.style.top = `${top}px`;
    };

    const show = (index) => {
      current = (index + sourceList.length) % sourceList.length;
      tooltip.querySelectorAll('.ai-chat-tooltip-entry').forEach((entry, i) => {
        entry.toggleAttribute('hidden', i !== current);
      });
      const counter = tooltip.querySelector('.ai-chat-tooltip-counter');
      if (counter) {
        counter.textContent = `${current + 1} / ${sourceList.length}`;
      }
      tooltip.querySelector('.ai-chat-tooltip-body').scrollTop = 0;
    };

    const open = () => {
      cancelClose();
      if (tooltip) return; // already open

      tooltip = document.createElement('div');
      tooltip.className = 'ai-chat-source-tooltip ai-chat-source-info-tooltip';
      tooltip.setAttribute('role', 'tooltip');

      let html = '';
      if (sourceList.length > 1) {
        html += `<div class="ai-chat-tooltip-nav">
          <button type="button" class="ai-chat-tooltip-prev" aria-label="${this.escapeHtml(this.lang.sourcePrevious)}">‹</button>
          <span class="ai-chat-tooltip-counter"></span>
          <button type="button" class="ai-chat-tooltip-next" aria-label="${this.escapeHtml(this.lang.sourceNext)}">›</button>
        </div>`;
      }

      html += '<div class="ai-chat-tooltip-body">';
      sourceList.forEach((sourceData) => {
        html += `<div class="ai-chat-tooltip-entry"><div class="ai-chat-tooltip-filename">${this.escapeHtml(sourceData.filename)}</div>`;

        if (sourceData.pages && sourceData.pages.length > 0) {
          const pageLabel = sourceData.pages.length === 1
            ? (this.container.dataset.pageLabel || 'S.')
            : (this.container.dataset.pagesLabel || 'S.');
          html += `<div class="ai-chat-tooltip-pages">${this.escapeHtml(pageLabel)} ${this.escapeHtml(sourceData.pages.join(', '))}</div>`;
        }

        if (sourceData.excerpt) {
          html += `<div class="ai-chat-tooltip-excerpt">${this.renderMarkdown(sourceData.excerpt)}</div>`;
        }
        html += '</div>';
      });
      html += '</div>';

      tooltip.innerHTML = html;
      this.container.appendChild(tooltip);

      // Height of the longest source, so that switching does not resize the tooltip;
      // the maximum height of the tooltip still applies
      const body = tooltip.querySelector('.ai-chat-tooltip-body');
      if (sourceList.length > 1) {
        let height = 0;
        sourceList.forEach((sourceData, i) => {
          show(i);
          height = Math.max(height, body.scrollHeight);
        });
        body.style.height = `${height}px`;
      }

      // Keep the tooltip open while the mouse is over it
      tooltip.addEventListener('mouseenter', cancelClose);
      tooltip.addEventListener('mouseleave', scheduleClose);
      tooltip.addEventListener('click', (e) => {
        if (e.target.closest('.ai-chat-tooltip-prev')) {
          show(current - 1);
        } else if (e.target.closest('.ai-chat-tooltip-next')) {
          show(current + 1);
        }
      });

      show(0);
      position();

      window.addEventListener('scroll', onScroll, true);
      window.addEventListener('resize', close);
      document.addEventListener('pointerdown', onOutsidePointer, true);
    };

    const isTouchOnly = () => typeof window.matchMedia === 'function' && window.matchMedia('(hover: none)').matches;

    element.addEventListener('mouseenter', () => {
      if (!isTouchOnly()) {
        open();
      }
    });
    element.addEventListener('mouseleave', scheduleClose);
    element.addEventListener('focus', () => {
      // Keyboard focus only; a tap also focuses the chip
      let keyboard = true;
      try {
        keyboard = element.matches(':focus-visible');
      } catch (e) {
        // Browsers without :focus-visible
      }
      if (keyboard) {
        open();
      }
    });
    element.addEventListener('blur', () => {
      // Clicking a button in the tooltip moves the focus away from the chip
      if (tooltip && !tooltip.matches(':hover')) {
        scheduleClose();
      }
    });
    element.addEventListener('click', close);
    element.addEventListener('keydown', (e) => {
      if (!tooltip) return;
      if (e.key === 'ArrowRight' && sourceList.length > 1) {
        e.preventDefault();
        show(current + 1);
      } else if (e.key === 'ArrowLeft' && sourceList.length > 1) {
        e.preventDefault();
        show(current - 1);
      } else if (e.key === 'Escape') {
        close();
      }
    });
  }

  /**
   * Remove a trailing source section written by the AI
   *
   * @param {string} text - Message text potentially containing inline sources
   * @returns {string} Cleaned text without the sources section at the end
   */
  stripInlineSources(text) {
    if (!text) return '';

    const kw = '(Quellen|Sources|Quellenangaben|Quellenverzeichnis|Referenzen|References|Literatur)';

    // Patterns from most to least specific; optional leading --- and bold markers
    const patterns = [
      new RegExp(`\\n?---\\s*\\n+#{1,6}\\s*\\*{0,2}${kw}\\*{0,2}\\s*:?[\\s\\S]*$`, 'i'),
      new RegExp(`\\n#{1,6}\\s*\\*{0,2}${kw}\\*{0,2}\\s*:?[\\s\\S]*$`, 'i'),
      new RegExp(`\\n\\s*\\*{1,2}${kw}\\*{1,2}\\s*:?[\\s\\S]*$`, 'i'),
      new RegExp(`\\n\\s*_{1,2}${kw}_{1,2}\\s*:?[\\s\\S]*$`, 'i'),
      new RegExp(`\\n\\s*${kw}\\s*:[\\s\\S]*$`, 'i'),
    ];

    for (const pattern of patterns) {
      const match = text.match(pattern);
      // Only in the last 60 % of the text, to avoid removing regular content
      if (match && match.index > text.length * 0.4) {
        text = text.substring(0, match.index);
        break;
      }
    }

    text = text.replace(/\n\n\s*(-\s+[^\n]+\.(pdf|doc|docx|txt|md|csv)[^\n]*\n?)+$/i, '');

    text = text.replace(/\n\n\s*(\d+\.\s+[^\n]+\.(pdf|doc|docx|txt|md|csv)[^\n]*\n?)+$/i, '');

    text = text.replace(/\n\n?\s*([\t ]*[^\n]+,\s*(page|pages|Seite|Seiten)\s+[\d,\s]+\n?)+$/i, '');

    text = text.trimEnd();

    return text;
  }

  /**
   * Remove a trailing source section and return the web links found in it
   *
   * @param {string} text
   * @param {Array} ragSources - existing RAG sources (used to avoid duplicates)
   * @returns {{ text: string, webLinks: Array<{filename:string,url:string}> }}
   */
  stripInlineSourcesWithLinks(text, ragSources = []) {
    const ragFilenames = new Set((ragSources || []).map((s) => s.filename));

    const kw = '(Quellen|Sources|Quellenangaben|Quellenverzeichnis|Referenzen|References|Literatur)';
    const sectionRegex = new RegExp(`(?:\\n?---\\s*\\n+|\\n)#{1,6}\\s*\\*{0,2}${kw}\\*{0,2}\\s*:?[\\s\\S]*$`, 'i');
    const match = text.match(sectionRegex);
    const webLinks = [];

    if (match && match.index > text.length * 0.4) {
      const section = match[0];
      const linkRegex = /\[([^\]]+)\]\((https?:\/\/[^)]+)\)/g;
      let m;
      while ((m = linkRegex.exec(section)) !== null) {
        const label = m[1].trim();
        const url = m[2].trim();
        if (!ragFilenames.has(label) && !ragFilenames.has(url)) {
          webLinks.push({ filename: label, url, pages: [] });
        }
      }
    }

    return { text: this.stripInlineSources(text), webLinks };
  }

  createImageAttachment(attachment, container) {
    const imgDiv = document.createElement('div');
    imgDiv.className = 'ai-chat-message-image';
    imgDiv.style.cssText = `
            margin: 4px 0;
            border-radius: 8px;
            overflow: hidden;
            max-width: 300px;
            cursor: pointer;
        `;

    const img = document.createElement('img');
    img.style.cssText = `
            width: 100%;
            height: auto;
            max-height: 200px;
            object-fit: cover;
            border-radius: 8px;
        `;

    // Thumbnail for display, download URL for the full size
    const imgSrc = attachment.data_url || attachment.thumbnail_url || attachment.preview_url || attachment.download_url;
    debug('AIChatPageComponent: Using optimized image src:', imgSrc);
    img.src = imgSrc;
    img.alt = attachment.title || 'Image';
    img.title = attachment.title || 'Image';

    img.onerror = () => {
      debug('AIChatPageComponent: Image failed to load, showing placeholder');
      img.src = this.createImagePlaceholder();
      img.onerror = null;
    };

    img.addEventListener('click', () => {
      window.open(attachment.download_url || attachment.src, '_blank');
    });

    imgDiv.appendChild(img);
    container.appendChild(imgDiv);
  }

  createPdfAttachment(attachment, container) {
    debug('AIChatPageComponent: Creating PDF attachment:', attachment);

    const pdfDiv = document.createElement('div');
    pdfDiv.className = 'ai-chat-message-pdf';

    const previewSources = [
      attachment.preview_url,
      attachment.thumbnail_url,
      attachment.data_url,
    ].filter((url) => url && url !== null && url.trim() !== '');

    debug('AIChatPageComponent: PDF preview sources available:', previewSources);

    if (previewSources.length > 0) {
      const img = document.createElement('img');
      img.alt = attachment.title || 'PDF Preview';
      img.title = `Click to open PDF: ${attachment.title || 'Document'}`;
      img.style.cursor = 'pointer';
      img.style.maxWidth = '300px';
      img.style.maxHeight = '200px';
      img.style.border = '1px solid #ddd';
      img.style.borderRadius = '4px';

      let currentSourceIndex = 0;

      const tryNextSource = () => {
        if (currentSourceIndex < previewSources.length) {
          const source = previewSources[currentSourceIndex];
          debug('AIChatPageComponent: Trying PDF preview source:', source);
          img.src = source;
          currentSourceIndex++;
        } else {
          debug('AIChatPageComponent: All PDF preview sources failed, showing icon');
          img.src = this.createPdfIcon(attachment.title);
          img.onerror = null;
          img.style.width = '48px';
          img.style.height = '48px';
          img.style.border = 'none';
        }
      };

      img.onerror = tryNextSource;

      tryNextSource();

      pdfDiv.appendChild(img);
    } else {
      const pdfContainer = document.createElement('div');
      pdfContainer.className = 'ai-chat-pdf-container';
      pdfContainer.style.cssText = `
                display: flex;
                align-items: center;
                padding: 8px 12px;
                border-radius: 8px;
                border: 1px solid #d0d0d0;
                background: white;
                max-width: 300px;
                cursor: pointer;
                margin: 4px 0;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            `;

      const iconContainer = document.createElement('div');
      iconContainer.style.cssText = `
                width: 32px;
                height: 32px;
                border-radius: 6px;
                background: #ff6b6b;
                display: flex;
                align-items: center;
                justify-content: center;
                margin-right: 12px;
                flex-shrink: 0;
            `;

      const pdfIcon = document.createElement('div');
      pdfIcon.style.cssText = `
                font-size: 16px;
                color: white;
            `;
      pdfIcon.innerHTML = '📄';
      iconContainer.appendChild(pdfIcon);

      const fileInfoContainer = document.createElement('div');
      fileInfoContainer.style.cssText = `
                flex: 1;
                min-width: 0;
            `;

      const filename = document.createElement('div');
      filename.style.cssText = `
                font-size: 14px;
                font-weight: 600;
                color: #333;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                line-height: 1.2;
            `;
      const filenameText = (attachment.title || 'PDF Document').replace(/\.pdf$/i, '');
      filename.textContent = filenameText;

      const typeInfo = document.createElement('div');
      typeInfo.style.cssText = `
                font-size: 12px;
                color: #666;
                margin-top: 2px;
            `;
      const sizeKB = Math.round(attachment.size / 1024);
      typeInfo.textContent = `PDF • ${sizeKB} KB`;

      fileInfoContainer.appendChild(filename);
      fileInfoContainer.appendChild(typeInfo);

      pdfContainer.appendChild(iconContainer);
      pdfContainer.appendChild(fileInfoContainer);
      pdfDiv.appendChild(pdfContainer);
    }

    pdfDiv.addEventListener('click', () => {
      window.open(attachment.download_url, '_blank');
    });

    container.appendChild(pdfDiv);
  }

  createDocumentAttachment(attachment, container) {
    const docDiv = document.createElement('div');
    docDiv.className = 'ai-chat-message-document';

    const docContainer = document.createElement('div');
    docContainer.className = 'ai-chat-document-container';
    docContainer.style.cssText = `
            display: flex;
            align-items: center;
            padding: 8px 12px;
            border-radius: 8px;
            border: 1px solid #d0d0d0;
            background: white;
            max-width: 300px;
            cursor: pointer;
            margin: 4px 0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        `;

    let bgColor = '#6366f1';
    let docType = 'DOC';
    let docIcon = '📄';

    if (attachment.mime_type.includes('word')) {
      bgColor = '#2b579a';
      docType = 'DOC';
      docIcon = '📝';
    } else if (attachment.mime_type.includes('excel') || attachment.mime_type.includes('sheet')) {
      bgColor = '#217346';
      docType = 'XLS';
      docIcon = '📊';
    } else if (attachment.mime_type.includes('powerpoint') || attachment.mime_type.includes('presentation')) {
      bgColor = '#d24726';
      docType = 'PPT';
      docIcon = '📽️';
    }

    const iconContainer = document.createElement('div');
    iconContainer.style.cssText = `
            width: 32px;
            height: 32px;
            border-radius: 6px;
            background: ${bgColor};
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
            flex-shrink: 0;
        `;

    const docIconEl = document.createElement('div');
    docIconEl.style.cssText = `
            font-size: 16px;
            color: white;
        `;
    docIconEl.innerHTML = docIcon;
    iconContainer.appendChild(docIconEl);

    const fileInfoContainer = document.createElement('div');
    fileInfoContainer.style.cssText = `
            flex: 1;
            min-width: 0;
        `;

    const filename = document.createElement('div');
    filename.style.cssText = `
            font-size: 14px;
            font-weight: 600;
            color: #333;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.2;
        `;
    const filenameText = (attachment.title || 'Document').replace(/\.(docx?|xlsx?|pptx?)$/i, '');
    filename.textContent = filenameText;

    const typeInfo = document.createElement('div');
    typeInfo.style.cssText = `
            font-size: 12px;
            color: #666;
            margin-top: 2px;
        `;
    const sizeKB = Math.round(attachment.size / 1024);
    typeInfo.textContent = `${docType} • ${sizeKB} KB`;

    fileInfoContainer.appendChild(filename);
    fileInfoContainer.appendChild(typeInfo);

    docContainer.appendChild(iconContainer);
    docContainer.appendChild(fileInfoContainer);
    docDiv.appendChild(docContainer);

    docDiv.addEventListener('click', () => {
      window.open(attachment.download_url, '_blank');
    });

    container.appendChild(docDiv);
  }

  createGenericAttachment(attachment, container) {
    const genericDiv = document.createElement('div');
    genericDiv.className = 'ai-chat-message-generic';

    const genericContainer = document.createElement('div');
    genericContainer.className = 'ai-chat-generic-container';
    genericContainer.style.cssText = `
            display: flex;
            align-items: center;
            padding: 8px 12px;
            border-radius: 8px;
            border: 1px solid #d0d0d0;
            background: white;
            max-width: 300px;
            cursor: pointer;
            margin: 4px 0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        `;

    const iconContainer = document.createElement('div');
    iconContainer.style.cssText = `
            width: 32px;
            height: 32px;
            border-radius: 6px;
            background: #666666;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
            flex-shrink: 0;
        `;

    const genericIcon = document.createElement('div');
    genericIcon.style.cssText = `
            font-size: 16px;
            color: white;
        `;
    genericIcon.innerHTML = '📁';
    iconContainer.appendChild(genericIcon);

    const fileInfoContainer = document.createElement('div');
    fileInfoContainer.style.cssText = `
            flex: 1;
            min-width: 0;
        `;

    const filename = document.createElement('div');
    filename.style.cssText = `
            font-size: 14px;
            font-weight: 600;
            color: #333;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.2;
        `;
    const filenameText = (attachment.title || 'File').replace(/\.[^/.]+$/, '');
    filename.textContent = filenameText;

    const typeInfo = document.createElement('div');
    typeInfo.style.cssText = `
            font-size: 12px;
            color: #666;
            margin-top: 2px;
        `;
    const ext = attachment.title ? attachment.title.split('.').pop().toUpperCase() : 'FILE';
    const sizeKB = Math.round(attachment.size / 1024);
    typeInfo.textContent = `${ext} • ${sizeKB} KB`;

    fileInfoContainer.appendChild(filename);
    fileInfoContainer.appendChild(typeInfo);

    genericContainer.appendChild(iconContainer);
    genericContainer.appendChild(fileInfoContainer);
    genericDiv.appendChild(genericContainer);

    genericDiv.addEventListener('click', () => {
      window.open(attachment.download_url, '_blank');
    });

    container.appendChild(genericDiv);
  }

  createImagePlaceholder() {
    const simpleSvg = '<svg width="64" height="64" xmlns="http://www.w3.org/2000/svg"><rect width="64" height="64" fill="#f0f0f0" stroke="#ddd" stroke-width="1"/><text x="32" y="35" text-anchor="middle" font-family="Arial" font-size="12" fill="#666">Image</text></svg>';

    try {
      return `data:image/svg+xml;base64,${btoa(simpleSvg)}`;
    } catch (e) {
      debugError('Failed to create placeholder:', e);
      return 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
    }
  }

  createPdfIcon(title) {
    return `data:image/svg+xml;base64,${btoa(`
            <svg width="64" height="64" xmlns="http://www.w3.org/2000/svg">
                <rect width="64" height="64" fill="#ff6b6b" stroke="#e55555" stroke-width="2" rx="8"/>
                <text x="32" y="32" text-anchor="middle" dy="0.3em" font-family="Arial" font-size="12" fill="white" font-weight="bold">PDF</text>
                <text x="32" y="50" text-anchor="middle" dy="0.3em" font-family="Arial" font-size="6" fill="white">${title?.split('.')[0] || 'Document'}</text>
            </svg>
        `)}`;
  }

  /**
   * Stop current AI generation request
   */
  stopGeneration() {
    if (this.currentRequest) {
      debug('AIChatPageComponent: Stopping HTTP request');
      this.currentRequest.abort();
      this.currentRequest = null;
      this.setLoading(false);
      this.addMessageToDisplay('system', this.lang.generationStopped);
    }

    if (this.currentEventSource) {
      debug('AIChatPageComponent: Stopping streaming');
      this.stopStreaming();
    }
  }

  /**
   * Set the loading state of the chat interface
   *
   * @param {boolean} loading - Whether the chat is in loading state
   */
  setLoading(loading) {
    this.isLoading = loading;

    if (loading) {
      this.sendButton.innerHTML = `
                <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" focusable="false">
                    <path d="M0 2a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V2z"/>
                </svg>
            `;
      this.sendButton.className = 'ai-chat-composer-btn ai-chat-stop';
      this.sendButton.title = this.lang.stopGeneration || 'Stop generation';
      this.sendButton.disabled = false;
      this.showThinkingPlaceholder();
    } else {
      this.sendButton.innerHTML = `
                <svg width="20" height="20" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" focusable="false">
                    <path d="M8.99992 16V6.41407L5.70696 9.70704C5.31643 10.0976 4.68342 10.0976 4.29289 9.70704C3.90237 9.31652 3.90237 8.6835 4.29289 8.29298L9.29289 3.29298L9.36907 3.22462C9.76184 2.90427 10.3408 2.92686 10.707 3.29298L15.707 8.29298L15.7753 8.36915C16.0957 8.76192 16.0731 9.34092 15.707 9.70704C15.3408 10.0732 14.7618 10.0958 14.3691 9.7754L14.2929 9.70704L10.9999 6.41407V16C10.9999 16.5523 10.5522 17 9.99992 17C9.44764 17 8.99992 16.5523 8.99992 16Z"></path>
                </svg>
            `;
      this.sendButton.className = 'ai-chat-composer-btn ai-chat-send';
      this.updateSendButtonState();
      this.removeThinkingPlaceholder();
    }
  }

  /**
   * Animated indicator shown where the answer will appear
   *
   * @returns {HTMLElement}
   */
  createThinkingIndicator() {
    const indicator = document.createElement('div');
    indicator.className = 'ai-chat-thinking';
    indicator.setAttribute('role', 'status');
    indicator.innerHTML = '<span class="ai-chat-thinking-dots" aria-hidden="true"><span></span><span></span><span></span></span>'
      + `<span class="ai-chat-thinking-label">${this.escapeHtml(this.lang.thinking)}</span>`;
    return indicator;
  }

  /**
   * Show an answer placeholder with the thinking indicator
   *
   * A streamed answer takes the placeholder over (createStreamingMessageElement());
   * otherwise it is removed when the answer or an error message is shown.
   */
  showThinkingPlaceholder() {
    this.removeThinkingPlaceholder();
    if (!this.messagesArea) return;

    const messageEl = document.createElement('div');
    messageEl.className = 'ai-chat-message assistant ai-chat-pending';
    const contentEl = document.createElement('div');
    contentEl.className = 'ai-chat-message-content';
    contentEl.appendChild(this.createThinkingIndicator());
    messageEl.appendChild(contentEl);

    this.messagesArea.appendChild(messageEl);
    this.pendingMessage = { messageEl, contentEl };
    this.scrollToBottom(true);
  }

  /**
   * Remove the answer placeholder, if it was not taken over by a streamed answer
   */
  removeThinkingPlaceholder() {
    if (this.pendingMessage) {
      this.pendingMessage.messageEl.remove();
      this.pendingMessage = null;
    }
  }

  /**
   * Update the character counter display and styling
   */
  updateCharacterCounter() {
    this.updateSendButtonState();

    if (this.charCounter) {
      const { length } = this.inputArea.value;
      this.charCounter.textContent = length;

      const charCounterContainer = this.container.querySelector('.ai-chat-char-counter');

      if (charCounterContainer) {
        if (length > 0) {
          charCounterContainer.classList.add('has-text');
        } else {
          charCounterContainer.classList.remove('has-text');
        }
      }

      const isOverLimit = length > this.charLimit;

      this.charCounter.classList.remove('warning', 'error');
      if (length > this.charLimit * 0.9) {
        this.charCounter.classList.add('warning');
      }
      if (isOverLimit) {
        this.charCounter.classList.add('error');
      }
    }
  }

  /**
   * Adapt layout and height of the input to its content
   *
   * The input switches to the multi-line layout (text above the buttons) when the
   * text contains a line break or does not fit into the single-line layout. The
   * width of the single-line layout is used in both states; with the wider input
   * of the multi-line layout the text would fit again, and the layout would
   * toggle with every character.
   */
  resizeComposer() {
    const textarea = this.inputArea;
    const composer = this.container.querySelector('.ai-chat-composer');

    if (!textarea || !composer) return;

    const singleLineWidth = this.getSingleLineInputWidth(composer);
    // Not measurable while the chat is hidden; the state is kept
    if (singleLineWidth > 0) {
      const text = textarea.value;
      const isExpanded = /[\r\n]/.test(text) || this.measureInputTextWidth(text) > singleLineWidth;
      composer.setAttribute('data-expanded', isExpanded.toString());
    }

    // The height is measured after the layout change, which changes the width
    textarea.style.height = 'auto';
    const lineHeight = parseFloat(window.getComputedStyle(textarea).lineHeight) || 21;
    textarea.style.height = `${Math.min(textarea.scrollHeight, lineHeight * 10)}px`;
  }

  /**
   * Width available for text in the single-line layout of the input
   *
   * Computed from the input row minus the button columns, so it is the same in
   * both layouts.
   *
   * @param {HTMLElement} composer
   * @returns {number} Width in pixels, 0 or less if the chat is not visible
   */
  getSingleLineInputWidth(composer) {
    const inner = composer.querySelector('.ai-chat-composer-inner');
    const content = composer.querySelector('.ai-chat-composer-content');
    if (!inner || !content) return 0;

    const sum = (element, properties) => {
      const style = window.getComputedStyle(element);
      return properties.reduce((total, property) => total + (parseFloat(style[property]) || 0), 0);
    };

    // Three columns, two gaps
    const gaps = 2 * sum(inner, ['columnGap']);
    const buttons = ['.ai-chat-composer-leading', '.ai-chat-composer-trailing']
      .map((selector) => composer.querySelector(selector))
      .reduce((total, element) => total + (element ? element.offsetWidth : 0), 0);

    // A few pixels less, so that rounding never leaves a wrapped line in the single-line layout
    return inner.clientWidth - sum(inner, ['paddingLeft', 'paddingRight']) - gaps - buttons
      - sum(content, ['marginLeft', 'marginRight']) - sum(this.inputArea, ['paddingLeft', 'paddingRight']) - 4;
  }

  /**
   * Width of a text in the font of the input
   *
   * @param {string} text
   * @returns {number} Width in pixels
   */
  measureInputTextWidth(text) {
    const style = window.getComputedStyle(this.inputArea);
    if (this.textMeasureContext === undefined) {
      const canvas = document.createElement('canvas');
      this.textMeasureContext = typeof canvas.getContext === 'function' ? canvas.getContext('2d') : null;
    }
    if (!this.textMeasureContext) {
      // Estimate without canvas support
      return text.length * (parseFloat(style.fontSize) || 14) * 0.55;
    }
    this.textMeasureContext.font = `${style.fontStyle} ${style.fontWeight} ${style.fontSize} ${style.fontFamily}`;
    return this.textMeasureContext.measureText(text).width;
  }

  /**
   * Enable the send button only for a message that can be sent
   *
   * Disabled for an empty message, a message over the character limit and while
   * no AI service is available or the session has expired. While an answer is
   * loading, the button is the stop button and controlled by setLoading().
   */
  updateSendButtonState() {
    if (!this.sendButton || this.isLoading) return;

    const { length } = this.inputArea.value;
    const isOverLimit = length > this.charLimit;
    const isEmpty = this.inputArea.value.trim() === '';
    const isBlocked = this.serviceUnavailable || this.inputArea.disabled;

    this.sendButton.disabled = isOverLimit || isEmpty || isBlocked;
    this.sendButton.classList.toggle('disabled-over-limit', isOverLimit && !isBlocked);
    this.sendButton.title = isOverLimit
      ? (this.container.dataset.sendDisabledOverLimit
        || `Message too long (${length}/${this.charLimit} characters). Please shorten your message.`)
      : (this.container.dataset.sendAriaLabel || 'Send message');
  }

  /**
   * Handle file selection from input element
   *
   * @param {FileList} files - Files selected by user
   * @returns {Promise<void>}
   */
  async handleFileSelection(files) {
    if (!files || files.length === 0) {
      return;
    }

    for (const file of files) {
      await this.uploadFile(file);
    }

    // Reset the input, so that the same file can be selected again
    this.fileInput.value = '';
  }

  async uploadFile(file) {
    debug('AIChatPageComponent: Attempting to upload file:', {
      name: file.name,
      type: file.type,
      size: file.size,
      allowedTypes: this.allowedFileTypes,
    });

    const maxAttachments = parseInt(this.container.dataset.maxAttachmentsPerMessage, 10) || 5;
    if (this.attachments.length >= maxAttachments) {
      const errorMessage = this.container.dataset.errorMaxAttachments || `Maximum ${maxAttachments} attachments per message allowed.`;
      debug('AIChatPageComponent: Attachment limit exceeded');
      this.showAlert(errorMessage);
      return;
    }

    const maxSizeMB = parseInt(this.container.dataset.maxFileSizeMb, 10) || 5;
    const maxSize = maxSizeMB * 1024 * 1024;
    if (file.size > maxSize) {
      const errorMessage = this.container.dataset.errorFileTooLarge || `File too large. Maximum size is ${maxSizeMB}MB.`;
      debug('AIChatPageComponent: File size exceeded:', { size: file.size, maxSize });
      this.showAlert(errorMessage);
      return;
    }

    // Total size of all attachments of the message, the server checks it again on sending
    const maxTotalMB = parseInt(this.container.dataset.maxTotalUploadSizeMb, 10) || 25;
    const currentTotal = this.attachments.reduce((sum, attachment) => sum + (Number(attachment.size) || 0), 0);
    if (currentTotal + file.size > maxTotalMB * 1024 * 1024) {
      const errorMessage = this.container.dataset.errorTotalUploadTooLarge
        || `The attachments of a message may not exceed ${maxTotalMB} MB in total.`;
      this.showAlert(errorMessage);
      return;
    }

    const allowedTypes = this.allowedFileTypes.length > 0 ? this.allowedFileTypes : [
      'image/jpeg', 'image/png', 'image/gif', 'image/webp',
      'application/pdf', 'text/plain', 'text/csv', 'text/markdown',
    ];

    debug('AIChatPageComponent: File type validation:', {
      fileType: file.type,
      allowedTypes,
      isAllowed: allowedTypes.includes(file.type),
    });

    if (!allowedTypes.includes(file.type)) {
      let errorMessage = this.container.dataset.errorFileTypeNotAllowed || `File type not allowed: ${file.type}`;
      errorMessage = errorMessage.replace('%s', file.type);
      debug('AIChatPageComponent: File type not allowed');
      this.showAlert(errorMessage);
      return;
    }

    let dataUrl = null;
    if (file.type.startsWith('image/')) {
      dataUrl = await this.createImagePreview(file);
    }

    const uploadingThumbnail = this.createUploadPreview(file, dataUrl);

    try {
      const formData = new FormData();
      formData.append('action', 'upload_file');
      formData.append('chat_id', this.chatId);
      formData.append('persistent', this.persistent);
      formData.append('file', file);

      const response = await fetch(this.apiUrl, {
        method: 'POST',
        body: formData,
      });

      if (!response.ok) {
        let errorMsg = 'Upload failed';
        try {
          const responseText = await response.text();
          if (responseText) {
            try {
              const errorData = JSON.parse(responseText);
              if (errorData.error) {
                errorMsg = errorData.error;
                if (errorData.details) {
                  debugError('Upload error details:', errorData.details);
                }
              }
            } catch (jsonError) {
              debugError('Response is not JSON. Raw response:', responseText.substring(0, 500));
              // Technical details are only logged
            }
          } else {
            debugError('Empty response received');
          }
        } catch (textError) {
          debugError('Could not read response text:', textError);
          // Technical details are only logged
        }
        throw new Error(errorMsg);
      }

      const data = await response.json();
      debug('AIChatPageComponent: Upload response data:', data);

      if (!data.success) {
        const errorMsg = data.error || 'Upload failed';
        if (data.details) {
          debugError('Upload error details:', data.details);
          // Technical details are only logged
        }
        throw new Error(errorMsg);
      }

      const { attachment } = data;
      debug('AIChatPageComponent: Server attachment data:', attachment);
      if (dataUrl && attachment.is_image) {
        attachment.data_url = dataUrl;
        debug('AIChatPageComponent: Added local data_url to attachment');
      }

      if (uploadingThumbnail) {
        this.clearThumbnailUploading(uploadingThumbnail);
      }

      this.attachments.push(attachment);
      this.updateAttachmentsDisplay();
    } catch (error) {
      debugError('File upload failed:', error);
      let errorMessage = this.container.dataset.errorFileUploadFailed || `File upload failed: ${error.message}`;
      errorMessage = errorMessage.replace('%s', error.message);
      this.showAlert(errorMessage);

      if (uploadingThumbnail) {
        this.clearThumbnailUploading(uploadingThumbnail);
      }
    }
  }

  /**
   * Creates a temporary thumbnail preview with circular progress indicator for file uploads
   *
   * @param {File} file - The file being uploaded
   * @param {string|null} dataUrl - Preview URL for images, null for other file types
   * @returns {HTMLElement|null} The created upload preview element
   */
  createUploadPreview(file, dataUrl) {
    if (!this.attachmentsList) return null;

    this.attachmentsArea.style.display = 'flex';
    this.attachmentsArea.style.cssText += `
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 8px;
            padding: 8px;
        `;

    const uploadPreview = document.createElement('div');
    uploadPreview.className = 'ai-chat-attachment-uploading';
    uploadPreview.style.cssText = `
            position: relative;
            margin: 4px 0;
            border-radius: 8px;
            overflow: hidden;
            width: 80px;
            height: 80px;
            cursor: pointer;
            flex-shrink: 0;
        `;

    if (file.type.startsWith('image/') && dataUrl) {
      const img = document.createElement('img');
      img.style.cssText = `
                width: 100%;
                height: 100%;
                object-fit: cover;
                border-radius: 8px;
            `;
      img.src = dataUrl;
      uploadPreview.appendChild(img);
    } else {
      const fileIcon = document.createElement('div');
      fileIcon.style.cssText = `
                width: 100%;
                height: 100%;
                background: var(--chat-bg-secondary, #f5f5f5);
                border: 1px solid var(--chat-border, #ddd);
                border-radius: 8px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 24px;
            `;
      fileIcon.textContent = file.type.includes('pdf') ? '📄' : '📎';
      uploadPreview.appendChild(fileIcon);
    }

    const circleDiv = document.createElement('div');
    circleDiv.className = 'ai-chat-upload-circle';
    circleDiv.innerHTML = `
            <svg viewBox="0 0 20 20">
                <circle cx="10" cy="10" r="8"></circle>
            </svg>
        `;
    uploadPreview.appendChild(circleDiv);

    this.attachmentsList.appendChild(uploadPreview);

    return uploadPreview;
  }

  /**
   * Removes the temporary upload preview thumbnail
   *
   * @param {HTMLElement} thumbnail - The upload preview element to remove
   */
  clearThumbnailUploading(thumbnail) {
    if (!thumbnail) return;

    // updateAttachmentsDisplay() creates the final thumbnail
    thumbnail.remove();
  }

  updateAttachmentsDisplay() {
    debug('AIChatPageComponent: updateAttachmentsDisplay called', {
      attachmentsList: !!this.attachmentsList,
      attachmentsCount: this.attachments.length,
      attachments: this.attachments,
    });

    if (!this.attachmentsList) {
      debug('AIChatPageComponent: attachmentsList not found, cannot update display');
      return;
    }

    this.attachmentsList.innerHTML = '';

    if (this.attachments.length === 0) {
      this.attachmentsArea.style.display = 'none';
      debug('AIChatPageComponent: No attachments, hiding area');
      return;
    }

    this.attachmentsArea.style.display = 'flex';
    this.attachmentsArea.style.cssText += `
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 8px;
            padding: 8px;
        `;

    debug('AIChatPageComponent: Showing attachments area, processing attachments');

    this.attachments.forEach((attachment, index) => {
      debug('AIChatPageComponent: Processing attachment', {
        index,
        attachment,
        is_image: attachment.is_image,
        has_data_url: !!attachment.data_url,
        has_preview_url: !!attachment.preview_url,
      });

      if (attachment.is_image && (attachment.data_url || attachment.preview_url)) {
        debug('AIChatPageComponent: Creating image preview for attachment', index);
        this.createUploadImagePreview(attachment, index);
      } else {
        debug('AIChatPageComponent: Creating document preview for attachment', index);
        this.createUploadDocumentPreview(attachment, index);
      }
      this.appendRagStatusBadge(this.attachmentsList, attachment);
    });

    debug('AIChatPageComponent: updateAttachmentsDisplay completed');
  }

  createUploadImagePreview(attachment, index) {
    const imageContainer = document.createElement('div');
    imageContainer.style.cssText = `
            position: relative;
            margin: 4px 0;
            border-radius: 8px;
            overflow: hidden;
            width: 80px;
            height: 80px;
            cursor: pointer;
            flex-shrink: 0;
        `;

    const img = document.createElement('img');
    img.style.cssText = `
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 8px;
        `;
    const imgSrc = attachment.data_url || attachment.preview_url;
    img.src = imgSrc;
    img.alt = attachment.title;

    const removeBtn = this.createRemoveButton(index);

    imageContainer.appendChild(img);
    imageContainer.appendChild(removeBtn);
    this.attachmentsList.appendChild(imageContainer);
  }

  createUploadDocumentPreview(attachment, index) {
    const docContainer = document.createElement('div');
    docContainer.style.cssText = `
            position: relative;
            display: flex;
            align-items: center;
            padding: 8px 12px;
            border-radius: 8px;
            border: 1px solid #d0d0d0;
            background: white;
            width: 200px;
            height: 80px;
            cursor: pointer;
            margin: 4px 0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            flex-shrink: 0;
        `;

    let bgColor = '#ff6b6b';
    let docType = 'PDF';
    let docIcon = '📄';

    if (attachment.mime_type) {
      if (attachment.mime_type.includes('word')) {
        bgColor = '#2b579a';
        docType = 'DOC';
        docIcon = '📝';
      } else if (attachment.mime_type.includes('excel') || attachment.mime_type.includes('sheet')) {
        bgColor = '#217346';
        docType = 'XLS';
        docIcon = '📊';
      } else if (attachment.mime_type.includes('powerpoint') || attachment.mime_type.includes('presentation')) {
        bgColor = '#d24726';
        docType = 'PPT';
        docIcon = '📽️';
      } else if (!attachment.mime_type.includes('pdf')) {
        bgColor = '#666666';
        docType = attachment.title ? attachment.title.split('.').pop().toUpperCase() : 'FILE';
        docIcon = '📁';
      }
    }

    const iconContainer = document.createElement('div');
    iconContainer.style.cssText = `
            width: 28px;
            height: 28px;
            border-radius: 6px;
            background: ${bgColor};
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 10px;
            flex-shrink: 0;
        `;

    const fileIcon = document.createElement('div');
    fileIcon.style.cssText = `
            font-size: 14px;
            color: white;
        `;
    fileIcon.innerHTML = docIcon;
    iconContainer.appendChild(fileIcon);

    const fileInfoContainer = document.createElement('div');
    fileInfoContainer.style.cssText = `
            flex: 1;
            min-width: 0;
        `;

    const filename = document.createElement('div');
    filename.style.cssText = `
            font-size: 13px;
            font-weight: 600;
            color: #333;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.2;
        `;
    const filenameText = (attachment.title || 'Document').replace(/\.(pdf|docx?|xlsx?|pptx?)$/i, '');
    filename.textContent = filenameText;

    const typeInfo = document.createElement('div');
    typeInfo.style.cssText = `
            font-size: 11px;
            color: #666;
            margin-top: 1px;
        `;
    const sizeKB = attachment.size ? Math.round(attachment.size / 1024) : '?';
    typeInfo.textContent = `${docType} • ${sizeKB} KB`;

    fileInfoContainer.appendChild(filename);
    fileInfoContainer.appendChild(typeInfo);

    const removeBtn = this.createRemoveButton(index);

    docContainer.appendChild(iconContainer);
    docContainer.appendChild(fileInfoContainer);
    docContainer.appendChild(removeBtn);
    this.attachmentsList.appendChild(docContainer);
  }

  createRemoveButton(index) {
    const removeBtn = document.createElement('button');
    removeBtn.className = 'ai-chat-upload-remove';
    removeBtn.style.cssText = `
            position: absolute;
            top: 4px;
            right: 4px;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.9);
            color: #333;
            border: 1px solid rgba(0, 0, 0, 0.1);
            font-family: Arial, sans-serif;
            font-size: 12px;
            font-weight: bold;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10;
            line-height: 1;
            padding: 0;
            margin: 0;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.12);
        `;
    removeBtn.textContent = '×';
    removeBtn.title = this.container.dataset.removeAttachment || 'Anhang entfernen';
    removeBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      this.removeAttachment(index);
    });

    return removeBtn;
  }

  removeAttachment(index) {
    this.attachments.splice(index, 1);
    this.updateAttachmentsDisplay();
  }

  clearAttachments() {
    this.attachments = [];
    this.updateAttachmentsDisplay();
  }

  async createImagePreview(file) {
    return new Promise((resolve) => {
      const reader = new window.FileReader();
      reader.onload = (e) => {
        resolve(e.target.result);
      };
      reader.onerror = () => {
        resolve(null);
      };
      reader.readAsDataURL(file);
    });
  }

  /**
   * Disable input and show a notice when no AI service is configured
   */
  disableInputForUnavailableService(showMessage = true) {
    this.serviceUnavailable = true;

    if (this.inputArea) this.inputArea.disabled = true;

    const sendBtn = this.container.querySelector('.ai-chat-send');
    if (sendBtn) sendBtn.disabled = true;

    const attachBtn = this.container.querySelector('.ai-chat-attach-btn');
    if (attachBtn) attachBtn.disabled = true;

    if (showMessage) {
      const notice = this.container.dataset.noServiceAvailable
                || 'No AI service is currently available.';
      setTimeout(() => this.addMessageToDisplay('system', notice), 100);
    }
  }

  saveChatHistory() {
    // Anonymous users: nothing is stored
    if (this.isAnonymous) return;
    window.localStorage.setItem(`ai_chat_${this.chatId}`, JSON.stringify(this.messageHistory));
  }

  async loadChatHistory() {
    if (this.persistent) {
      try {
        const response = await fetch(this.apiUrl, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
          },
          body: JSON.stringify({
            action: 'load_chat',
            chat_id: this.chatId,
          }),
        });

        if (response.ok) {
          const data = await response.json();
          debug('AIChatPageComponent: Loaded chat data:', data);
          if (data.success && data.messages) {
            debug('AIChatPageComponent: Raw messages from server:', data.messages);
            this.messageHistory = data.messages.map((msg) => ({
              role: msg.role,
              content: msg.content || msg.message || '',
              timestamp: msg.timestamp || Date.now(),
              attachments: msg.attachments || [],
              sources: msg.sources || null,
              usage: msg.usage || null,
            }));

            debug('AIChatPageComponent: Processed message history:', this.messageHistory);
            this.messageHistory.forEach((msg) => {
              if (msg.role !== 'system') {
                debug('AIChatPageComponent: Displaying message:', msg);
                // Messages of uploads without text are empty
                if (msg.content.trim() === '' && (!msg.attachments || msg.attachments.length === 0)) {
                  debug('AIChatPageComponent: Skipping empty message');
                  return;
                }
                this.displayMessageOnly(msg.role, msg.content, msg.attachments || [], msg.sources, msg.usage);
              }
            });
          }
        }
      } catch (e) {
        debugError('Failed to load persistent chat history:', e);
        this.loadLocalChatHistory();
      }
    } else {
      // Non-persistent chats start empty
      debug('Non-persistent chat - starting fresh without loading history');
      this.messageHistory = [];
      window.localStorage.removeItem(`ai_chat_${this.chatId}`);
    }

    if (this.messageHistory.length === 0) {
      this.showWelcomeMessage();
    }
  }

  loadLocalChatHistory() {
    const saved = window.localStorage.getItem(`ai_chat_${this.chatId}`);
    if (saved) {
      try {
        this.messageHistory = JSON.parse(saved);
        this.messageHistory.forEach((msg) => {
          if (msg.role !== 'system') {
            this.displayMessageOnly(msg.role, msg.content, msg.attachments || []);
          }
        });
      } catch (e) {
        debugError('Failed to load local chat history:', e);
      }
    }

    if (this.messageHistory.length === 0) {
      this.showWelcomeMessage();
    }
  }

  showWelcomeMessage() {
    this.messagesArea.innerHTML = `<div class="ai-chat-welcome">${this.lang.welcomeMessage}</div>`;
  }

  /**
   * Regenerate the last assistant response
   */
  async regenerateResponse(messageDiv) {
    debug('AIChatPageComponent: Regenerating response');

    const messages = this.messagesArea.querySelectorAll('.ai-chat-message');
    let lastUserMessage = null;
    let lastUserAttachments = [];

    for (let i = messages.length - 1; i >= 0; i--) {
      if (messages[i] === messageDiv) {
        for (let j = i - 1; j >= 0; j--) {
          if (messages[j].classList.contains('user')) {
            lastUserMessage = messages[j];
            break;
          }
        }
        break;
      }
    }

    if (!lastUserMessage) {
      debugError('AIChatPageComponent: Could not find user message to regenerate from');
      return;
    }

    const userContent = lastUserMessage.querySelector('.ai-chat-message-content').textContent;

    const attachmentDivs = lastUserMessage.querySelectorAll('.ai-chat-message-image img, .ai-chat-message-attachment');
    for (const attachmentDiv of attachmentDivs) {
      if (attachmentDiv.tagName === 'IMG') {
        const { attachmentId } = attachmentDiv.dataset;
        if (attachmentId) {
          const historyMsg = this.messageHistory.find((msg) => msg.attachments && msg.attachments.some((att) => String(att.id) === attachmentId));
          if (historyMsg) {
            lastUserAttachments = historyMsg.attachments;
          }
        }
      }
    }

    debug('AIChatPageComponent: Regenerating with message:', userContent);
    debug('AIChatPageComponent: With attachments:', lastUserAttachments);

    messageDiv.remove();

    this.setLoading(true);

    try {
      if (lastUserAttachments.length > 0) {
        if (this.enableStreaming) {
          await this.sendMessageToAIStream(userContent, lastUserAttachments);
        } else {
          await this.sendMessageWithFiles(userContent, lastUserAttachments);
        }
      } else if (this.enableStreaming) {
        await this.sendMessageToAIStream(userContent);
      } else {
        await this.sendMessageToAI(userContent);
      }
    } catch (error) {
      debugError('AIChatPageComponent: Regenerate failed:', error);
      this.setLoading(false);
      this.addMessageToDisplay('system', this.lang.regenerateFailed);
    }
  }

  copyMessageToClipboard(content, button) {
    if (window.navigator.clipboard && window.isSecureContext) {
      window.navigator.clipboard.writeText(content).then(() => {
        this.showMessageCopyFeedback(button, this.lang.messageCopied);
      }).catch(() => {
        this.fallbackMessageCopy(content, button);
      });
    } else {
      this.fallbackMessageCopy(content, button);
    }
  }

  /**
   * Copy via a temporary text field if the Clipboard API is not available
   */
  fallbackMessageCopy(text, button) {
    const textArea = document.createElement('textarea');
    textArea.value = text;
    textArea.style.position = 'fixed';
    textArea.style.left = '-999999px';
    textArea.style.top = '-999999px';
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();

    try {
      document.execCommand('copy');
      this.showMessageCopyFeedback(button, this.lang.messageCopied);
    } catch (err) {
      this.showMessageCopyFeedback(button, this.lang.messageCopyFailed);
    }

    document.body.removeChild(textArea);
  }

  /**
   * Show a short feedback next to the copy button
   */
  showMessageCopyFeedback(button, message) {
    debug('AIChatPageComponent: Starting copy feedback animation for:', message);

    const originalTitle = button.title;

    const originalPosition = button.style.position;
    const originalOverflow = button.style.overflow;
    button.style.position = 'relative';
    button.style.overflow = 'visible';

    debug('AIChatPageComponent: Button prepared for animation');

    const textElement = document.createElement('span');
    textElement.textContent = message;
    textElement.style.cssText = `
            position: absolute;
            top: 50%;
            left: -20px;
            transform: translateY(-50%);
            background: var(--chat-accent);
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
            white-space: nowrap;
            z-index: 1000;
            transition: left 0.4s ease-out;
            pointer-events: none;
        `;

    button.appendChild(textElement);
    button.disabled = true;

    debug('AIChatPageComponent: Text element created and added');

    setTimeout(() => {
      debug('AIChatPageComponent: Triggering slide-in animation');
      textElement.style.left = '32px';
    }, 50);

    setTimeout(() => {
      debug('AIChatPageComponent: Starting slide-out animation');
      textElement.style.transition = 'left 0.4s ease-in, opacity 0.3s ease-in';
      textElement.style.left = '100px';
      textElement.style.opacity = '0';

      setTimeout(() => {
        debug('AIChatPageComponent: Cleaning up animation');
        try {
          if (textElement.parentNode) {
            button.removeChild(textElement);
          }
          button.style.position = originalPosition;
          button.style.overflow = originalOverflow;
          button.disabled = false;
          button.title = originalTitle;
        } catch (error) {
          debugError('AIChatPageComponent: Error cleaning up animation:', error);
        }
      }, 400);
    }, 2000);
  }

  /**
   * Show the processing state of an uploaded file in the document search next to the file
   *
   * Files that are not handled by the document search have no state. While a file
   * is being processed, the state is queried from the server.
   *
   * @param {HTMLElement} container - Element the badge is appended to
   * @param {Object} attachment - Attachment data with id and rag_status
   */
  appendRagStatusBadge(container, attachment) {
    if (!container || !attachment || !this.lang.ragUploadStatus[attachment.rag_status]) {
      return;
    }

    const badge = document.createElement('div');
    badge.className = 'ai-chat-rag-status';
    badge.dataset.attachmentId = attachment.id;
    this.setRagStatusBadge(badge, attachment.rag_status);
    container.appendChild(badge);

    if (this.isRagStatusPending(attachment.rag_status)) {
      this.startRagStatusPolling();
    }
  }

  setRagStatusBadge(badge, status) {
    badge.dataset.status = status;
    badge.textContent = this.lang.ragUploadStatus[status];
  }

  isRagStatusPending(status) {
    // Failed files are uploaded again by the server
    return status === 'pending' || status === 'processing' || status === 'failed';
  }

  /**
   * Query the processing states until all shown files are processed: every 5 seconds
   * in the first minute, then every 30 seconds, after 10 minutes once per minute,
   * at most for 30 minutes
   */
  startRagStatusPolling() {
    if (this.ragStatusTimer || this.isAnonymous) {
      return;
    }
    this.ragStatusPollingSince = Date.now();
    this.scheduleRagStatusPoll();
  }

  scheduleRagStatusPoll() {
    const elapsed = Date.now() - this.ragStatusPollingSince;
    if (elapsed > 30 * 60 * 1000) {
      this.ragStatusTimer = null;
      return;
    }

    let delay = 60000;
    if (elapsed < 60000) {
      delay = 5000;
    } else if (elapsed < 600000) {
      delay = 30000;
    }
    this.ragStatusTimer = setTimeout(() => this.pollRagStatus(), delay);
  }

  async pollRagStatus() {
    this.ragStatusTimer = null;

    let files = {};
    try {
      const response = await fetch(this.apiUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          action: 'rag_status',
          chat_id: this.chatId,
        }),
      });
      const data = response.ok ? await response.json() : {};
      files = data.files || {};
    } catch (error) {
      debugError('AIChatPageComponent: RAG status request failed:', error);
    }

    // Keep the state of files that are not sent yet, their preview is rendered again
    this.attachments.forEach((attachment) => {
      if (files[attachment.id]) {
        attachment.rag_status = files[attachment.id];
      }
    });

    let pending = false;
    this.container.querySelectorAll('.ai-chat-rag-status').forEach((badge) => {
      const status = files[badge.dataset.attachmentId];
      if (status && this.lang.ragUploadStatus[status]) {
        this.setRagStatusBadge(badge, status);
      }
      pending = pending || this.isRagStatusPending(badge.dataset.status);
    });

    if (pending && !this.ragStatusTimer) {
      this.scheduleRagStatusPoll();
    }
  }

  /**
   * Note below the last answer that background files were not usable in the RAG
   */
  showRagIncompleteNotice() {
    const answers = this.messagesArea.querySelectorAll('.ai-chat-message.assistant');
    const lastAnswer = answers[answers.length - 1];
    if (!lastAnswer || lastAnswer.querySelector('.ai-chat-rag-notice')) {
      return;
    }

    const notice = document.createElement('div');
    notice.className = 'ai-chat-rag-notice';
    notice.setAttribute('role', 'note');
    notice.textContent = this.lang.ragIncompleteNotice;
    lastAnswer.appendChild(notice);
  }

  /**
   * Announce message to screen readers via live region
   *
   * @param {string} message - Message to announce
   */
  announceToScreenReader(message) {
    if (!this.srStatus) {
      return;
    }

    // Cleared first, so that the same text is announced again
    this.srStatus.textContent = '';

    window.requestAnimationFrame(() => {
      this.srStatus.textContent = message;

      setTimeout(() => {
        if (this.srStatus) {
          this.srStatus.textContent = '';
        }
      }, 1000);
    });
  }

  /**
   * Clear all chat messages and history
   *
   * @returns {Promise<void>}
   */
  async clearChatHistory() {
    debug('AIChatPageComponent: clearChatHistory called');
    debug('AIChatPageComponent: Container dataset:', this.container.dataset);

    let confirmText = this.container.dataset.clearChatConfirm || 'Are you sure you want to clear all chat messages? This action cannot be undone.';

    // The text may contain HTML entities
    if (confirmText.includes('&')) {
      const textarea = document.createElement('textarea');
      textarea.innerHTML = confirmText;
      confirmText = textarea.value;
    }

    debug('AIChatPageComponent: Confirm text:', confirmText);

    debug('AIChatPageComponent: Showing ILIAS confirmation dialog');
    const userConfirmed = await this.showCustomConfirmDialog(confirmText);

    if (!userConfirmed) {
      debug('AIChatPageComponent: User cancelled clear chat');
      return;
    }

    debug('AIChatPageComponent: Starting clear chat request');

    try {
      const response = await fetch(this.apiUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          action: 'clear_chat',
          chat_id: this.chatId,
        }),
      });

      debug('AIChatPageComponent: Clear chat response:', response.status);

      if (response.ok) {
        const data = await response.json();
        debug('AIChatPageComponent: Clear chat data:', data);

        if (data.success) {
          debug('AIChatPageComponent: Chat cleared successfully, updating UI');
          this.messagesArea.innerHTML = `<div class="ai-chat-welcome">${this.lang.welcomeMessage || 'Start a conversation...'}</div>`;
          this.messageHistory = [];

          if (!this.persistent) {
            window.localStorage.removeItem(`ai_chat_${this.chatId}`);
          }

          debug('AIChatPageComponent: UI cleared successfully');
        } else {
          debugError('AIChatPageComponent: Server returned error:', data.error);
          this.showAlert(`Error clearing chat: ${data.error || 'Unknown error'}`);
        }
      } else {
        debugError('AIChatPageComponent: HTTP error:', response.status);
        this.showAlert('Failed to clear chat. Please try again.');
      }
    } catch (error) {
      debugError('Failed to clear chat:', error);
      this.showAlert(`Error: ${error.message}`);
    }
  }

  /**
   * Show an alert in ILIAS modal style
   */
  async showAlert(message) {
    return new Promise((resolve) => {
      const backdrop = document.createElement('div');
      backdrop.className = 'modal-backdrop fade in';
      backdrop.style.cssText = `
                position: fixed;
                top: 0;
                right: 0;
                bottom: 0;
                left: 0;
                z-index: 1040;
                background-color: #000;
                opacity: 0.5;
            `;

      const modal = document.createElement('div');
      modal.className = 'modal fade in';
      modal.style.cssText = `
                position: fixed;
                top: 0;
                right: 0;
                bottom: 0;
                left: 0;
                z-index: 1050;
                overflow: auto;
                display: block;
            `;

      modal.innerHTML = `
                <div class="modal-dialog" style="
                    width: 600px;
                    margin: 30px auto;
                    position: relative;
                ">
                    <div class="modal-content" style="
                        background-color: #fff;
                        border: 1px solid #999;
                        border-radius: 6px;
                        box-shadow: 0 3px 9px rgba(0,0,0,.5);
                        outline: 0;
                    ">
                        <div class="modal-header" style="
                            padding: 15px;
                            border-bottom: 1px solid #e5e5e5;
                            background-color: #f5f5f5;
                            border-radius: 6px 6px 0 0;
                        ">
                            <h4 class="modal-title" style="
                                margin: 0;
                                font-size: 18px;
                                line-height: 1.42857143;
                                color: #333;
                            ">Information</h4>
                        </div>
                        <div class="modal-body" style="
                            position: relative;
                            padding: 20px;
                        ">
                            <div class="alert alert-info" role="alert" style="
                                padding: 15px;
                                margin-bottom: 20px;
                                border: 1px solid #bce8f1;
                                border-radius: 4px;
                                color: #31708f;
                                background-color: #d9edf7;
                            ">${message}</div>
                        </div>
                        <div class="modal-footer" style="
                            padding: 15px;
                            text-align: right;
                            border-top: 1px solid #e5e5e5;
                            background-color: #f5f5f5;
                            border-radius: 0 0 6px 6px;
                        ">
                            <button type="button" class="btn btn-primary" id="alert-ok" style="
                                color: #fff;
                                background-color: #337ab7;
                                border-color: #2e6da4;
                                padding: 6px 12px;
                                margin-bottom: 0;
                                font-size: 14px;
                                font-weight: normal;
                                line-height: 1.42857143;
                                text-align: center;
                                white-space: nowrap;
                                vertical-align: middle;
                                cursor: pointer;
                                border: 1px solid transparent;
                                border-radius: 4px;
                            ">OK</button>
                        </div>
                    </div>
                </div>
            `;

      document.body.appendChild(backdrop);
      document.body.appendChild(modal);
      document.body.classList.add('modal-open');

      const cleanup = () => {
        document.body.classList.remove('modal-open');
        document.body.removeChild(backdrop);
        document.body.removeChild(modal);
        resolve();
      };

      const okBtn = modal.querySelector('#alert-ok');
      okBtn.addEventListener('click', cleanup);

      okBtn.focus();
    });
  }

  /**
   * Show a confirmation dialog in ILIAS modal style
   */
  showCustomConfirmDialog(message) {
    debug('AIChatPageComponent: Showing custom confirm dialog');

    const backdrop = document.createElement('div');
    backdrop.className = 'modal-backdrop fade in';
    backdrop.style.cssText = `
            position: fixed;
            top: 0;
            right: 0;
            bottom: 0;
            left: 0;
            z-index: 1040;
            background-color: #000;
            opacity: 0.5;
        `;

    const modal = document.createElement('div');
    modal.className = 'modal fade in';
    modal.style.cssText = `
            position: fixed;
            top: 0;
            right: 0;
            bottom: 0;
            left: 0;
            z-index: 1050;
            display: block;
            overflow: auto;
        `;

    const dialog = document.createElement('div');
    dialog.className = 'modal-dialog';

    const updateDialogSize = () => {
      const isDesktop = window.innerWidth >= 992;
      const dialogWidth = isDesktop ? '600px' : 'calc(100vw - 20px)';
      const dialogMargin = isDesktop ? '30px auto' : '10px';

      dialog.style.cssText = `
                position: relative;
                width: ${dialogWidth};
                max-width: 600px;
                margin: ${dialogMargin};
            `;
    };

    updateDialogSize();

    dialog.innerHTML = `
            <div class="modal-content" style="
                position: relative;
                background-color: #fff;
                background-clip: padding-box;
                border: 1px solid rgba(0, 0, 0, 0.2);
                border-radius: 0px;
                outline: 0;
                box-shadow: 0 5px 15px rgba(0, 0, 0, 0.5);
            ">
                <div class="modal-header" style="
                    padding: 9px 15px;
                    border-bottom: 1px solid #e5e5e5;
                ">
                    <button type="button" class="close" aria-label="Abbrechen" style="
                        float: right;
                        font-size: 21px;
                        font-weight: bold;
                        line-height: 1;
                        color: #000;
                        text-shadow: 0 1px 0 #fff;
                        opacity: 0.2;
                        background: transparent;
                        border: 0;
                        cursor: pointer;
                    ">
                        <span aria-hidden="true">×</span>
                    </button>
                    <h1 class="modal-title" style="
                        font-size: 1rem;
                        margin: 0;
                        line-height: 1.428571429;
                        padding: 0px 39px;
                    ">Chat löschen</h1>
                </div>
                <div class="modal-body" style="
                    position: relative;
                    padding: 9px 15px;
                ">
                    <div class="alert alert-warning c-modal--interruptive__message" role="alert" style="
                        padding: 15px;
                        margin-bottom: 20px;
                        border: 1px solid transparent;
                        border-radius: 4px;
                        color: #8a6d3b;
                        background-color: #fcf8e3;
                        border-color: #faebcc;
                    ">
                        ${message}
                    </div>
                </div>
                <div class="modal-footer" style="
                    padding: 9px 15px;
                    text-align: right;
                    border-top: 1px solid #e5e5e5;
                ">
                    <button type="button" class="btn btn-primary" id="confirm-delete" style="
                        display: inline-block;
                        margin-bottom: 0;
                        font-weight: normal;
                        text-align: center;
                        vertical-align: middle;
                        touch-action: manipulation;
                        cursor: pointer;
                        background-image: none;
                        border: 1px solid transparent;
                        white-space: nowrap;
                        padding: 6px 12px;
                        font-size: 14px;
                        line-height: 1.428571429;
                        border-radius: 4px;
                        color: #fff;
                        background-color: #337ab7;
                        border-color: #2e6da4;
                        margin-left: 5px;
                    ">Löschen</button>
                    <button type="button" class="btn btn-default" id="confirm-cancel" style="
                        display: inline-block;
                        margin-bottom: 0;
                        font-weight: normal;
                        text-align: center;
                        vertical-align: middle;
                        touch-action: manipulation;
                        cursor: pointer;
                        background-image: none;
                        border: 1px solid transparent;
                        white-space: nowrap;
                        padding: 6px 12px;
                        font-size: 14px;
                        line-height: 1.428571429;
                        border-radius: 4px;
                        color: #333;
                        background-color: #fff;
                        border-color: #ccc;
                        margin-left: 5px;
                    ">Abbrechen</button>
                </div>
            </div>
        `;

    modal.appendChild(dialog);

    document.body.appendChild(backdrop);
    document.body.appendChild(modal);
    document.body.classList.add('modal-open');

    return new Promise((resolve) => {
      const deleteBtn = modal.querySelector('#confirm-delete');
      const cancelBtn = modal.querySelector('#confirm-cancel');
      const closeBtn = modal.querySelector('.close');

      const resizeHandler = () => {
        updateDialogSize();
      };
      window.addEventListener('resize', resizeHandler);

      const cleanup = (result) => {
        document.body.classList.remove('modal-open');
        document.body.removeChild(backdrop);
        document.body.removeChild(modal);

        window.removeEventListener('resize', resizeHandler);

        debug('AIChatPageComponent: Custom dialog result:', result);
        resolve(result);
      };

      deleteBtn.addEventListener('click', () => cleanup(true));
      cancelBtn.addEventListener('click', () => cleanup(false));
      closeBtn.addEventListener('click', () => cleanup(false));

      backdrop.addEventListener('click', () => cleanup(false));

      const escapeHandler = (e) => {
        if (e.key === 'Escape') {
          cleanup(false);
          document.removeEventListener('keydown', escapeHandler);
        }
      };
      document.addEventListener('keydown', escapeHandler);

      deleteBtn.focus();
    });
  }

  /**
   * Hide the upload elements if chat uploads are disabled
   */
  hideFileUploadElements() {
    // Elements are missing if uploads are disabled on the server
    if (this.attachBtn) {
      this.attachBtn.style.display = 'none';
    }

    if (this.attachmentsArea) {
      this.attachmentsArea.style.display = 'none';
    }

    if (this.clearAttachmentsBtn) {
      this.clearAttachmentsBtn.style.display = 'none';
    }

    if (this.fileInput) {
      this.fileInput.style.display = 'none';
    }
  }

  /**
   * Update file input accept attribute based on global configuration
   */
  updateFileInputAcceptAttribute() {
    if (!this.fileInput) {
      return;
    }

    if (this.allowedAcceptValues && this.allowedAcceptValues.length > 0) {
      const acceptString = this.allowedAcceptValues.join(',');
      this.fileInput.setAttribute('accept', acceptString);
      debug('AIChatPageComponent: Updated file input accept attribute (from server):', acceptString);
      return;
    }

    // Fallback: build the values from the extensions
    if (!this.allowedExtensions || this.allowedExtensions.length === 0) {
      return;
    }

    const acceptValues = [];

    if (this.allowedFileTypes && this.allowedFileTypes.length > 0) {
      acceptValues.push(...this.allowedFileTypes);
    }

    this.allowedExtensions.forEach((ext) => {
      acceptValues.push(`.${ext}`);
    });

    const acceptString = acceptValues.join(',');
    this.fileInput.setAttribute('accept', acceptString);

    debug('AIChatPageComponent: Updated file input accept attribute (fallback):', acceptString);
  }

  /**
   * Rough check whether the ILIAS session is still active
   *
   * @returns {boolean} True if session appears to be valid
   */
  isSessionValid() {
    if (typeof window.$ !== 'undefined' && window.$.fn.ilSessionReminder) {
      const sessionCookies = document.cookie.split(';').filter((cookie) => cookie.trim().includes('PHPSESSID')
                || cookie.trim().includes('il_')
                || cookie.trim().includes('authtoken'));

      if (sessionCookies.length === 0) {
        debug('AIChatPageComponent: No ILIAS session cookies found');
        return false;
      }
    }

    if (typeof window.il === 'undefined' && typeof window.ILIAS === 'undefined') {
      debug('AIChatPageComponent: ILIAS globals not available, session may be expired');
      return false;
    }

    return true;
  }

  /**
   * Handle expired session with user-friendly messaging
   */
  handleSessionExpired() {
    debug('AIChatPageComponent: Session expired detected');

    const sessionExpiredMsg = this.container.dataset.sessionExpiredMessage
            || 'Your session has expired. Please refresh the page to log in again.';

    this.addMessageToDisplay('system', sessionExpiredMsg);

    this.inputArea.disabled = true;
    this.sendButton.disabled = true;

    if (this.container.dataset.showRefreshOnExpiry !== 'false') {
      this.showSessionExpiredOptions();
    }
  }

  /**
   * Show options for handling expired session
   */
  showSessionExpiredOptions() {
    const refreshText = this.container.dataset.refreshPageText || 'Refresh Page';
    const sessionDiv = document.createElement('div');
    sessionDiv.className = 'ai-chat-session-expired';
    sessionDiv.style.cssText = `
            margin: 10px 0;
            padding: 10px;
            border: 2px solid #f39c12;
            border-radius: 4px;
            background: #fff3cd;
            text-align: center;
        `;

    const refreshBtn = document.createElement('button');
    refreshBtn.textContent = refreshText;
    refreshBtn.className = 'btn btn-primary btn-sm';
    refreshBtn.style.cssText = 'margin: 5px;';
    refreshBtn.addEventListener('click', () => {
      window.location.reload();
    });

    sessionDiv.appendChild(refreshBtn);
    this.messagesArea.appendChild(sessionDiv);
    this.scrollToBottom();
  }

  /**
   * User-facing message for an error
   *
   * @param {Error} error - The error object to process
   * @returns {string} User-friendly error message
   */
  getErrorMessage(error) {
    // Sent by the server if no AI service is enabled
    if (error.message === 'no_service_available') {
      return this.container.dataset.noServiceAvailable
                || 'No AI service is currently available. Please contact your administrator.';
    }
    // Session expired (redirect to the login)
    if (error.message.includes('302') || error.message.includes('redirect')) {
      return 'Your session has expired. Please refresh the page to log in again.';
    }

    if (error.name === 'TypeError' && error.message.includes('fetch')) {
      return 'Unable to connect to the AI service. Please check your internet connection and try again.';
    }

    if (error.name === 'NetworkError' || error.message.includes('Failed to fetch')) {
      return 'Network connection failed. Please check your internet connection and try again.';
    }

    if (error.message.includes('API URL not configured')) {
      return 'AI service is not properly configured. Please contact your administrator.';
    }

    if (error.message.includes('Invalid API key') || error.message.includes('401')) {
      return 'Authentication failed. The AI service credentials need to be updated.';
    }

    if (error.message.includes('429') || error.message.includes('rate limit')) {
      return 'Too many requests. Please wait a moment and try again.';
    }

    if (error.message.includes('500') || error.message.includes('503')) {
      return 'The AI service is temporarily unavailable. Please try again later.';
    }

    if (error.message.includes('File too large')) {
      return error.message;
    }

    if (error.message.includes('file type not allowed')) {
      return error.message;
    }

    if (error.message.includes('JSON')) {
      return 'Received an invalid response from the AI service. Please try again.';
    }

    return `An error occurred while communicating with the AI service: ${error.message}. Please try again or contact support if the problem persists.`;
  }

  /**
   * Keep the messages scrolled to the end after new content
   *
   * Without force, the view only follows if the user has not scrolled up, so that
   * a streamed answer can be read from the beginning.
   *
   * @param {boolean} [force=false] - Scroll to the end in any case, e.g. for a sent message
   */
  scrollToBottom(force = false) {
    if (force || this.followMessages) {
      this.messagesArea.scrollTop = this.messagesArea.scrollHeight;
      this.followMessages = true;
    }
    this.updateScrollButton();
  }

  /**
   * Whether the messages are scrolled to the end, within a tolerance
   *
   * @returns {boolean}
   */
  isAtMessagesEnd() {
    const area = this.messagesArea;
    const distance = area.scrollHeight - area.scrollTop - area.clientHeight;
    return distance <= AIChatPageComponent.SCROLL_END_TOLERANCE;
  }

  /**
   * Show the button to the latest message while the messages are scrolled up
   */
  updateScrollButton() {
    if (!this.scrollButton) {
      return;
    }
    const visible = !this.isAtMessagesEnd();
    this.scrollButton.classList.toggle('visible', visible);
    this.scrollButton.setAttribute('aria-hidden', visible ? 'false' : 'true');
    this.scrollButton.tabIndex = visible ? 0 : -1;
  }

  /**
   * Render message content as Markdown
   */
  formatMessage(content) {
    return this.renderMarkdown(content);
  }

  /**
   * Create the action buttons of a message (copy, regenerate)
   */
  createMessageActions() {
    const actionsDiv = document.createElement('div');
    actionsDiv.className = 'ai-chat-message-actions';

    const copyBtn = document.createElement('button');
    copyBtn.className = 'ai-chat-message-action';
    copyBtn.title = this.lang.copyMessageTitle;
    copyBtn.innerHTML = `
            <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" focusable="false">
                <path d="M4 1.5H3a2 2 0 0 0-2 2V14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V3.5a2 2 0 0 0-2-2h-1v1h1a1 1 0 0 1 1 1V14a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V3.5a1 1 0 0 1 1-1h1v-1z"/>
                <path d="M9.5 1a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-.5.5h-3a.5.5 0 0 1-.5-.5v-1a.5.5 0 0 1 .5-.5h3zm-3-1A1.5 1.5 0 0 0 5 1.5v1A1.5 1.5 0 0 0 6.5 4h3A1.5 1.5 0 0 0 11 2.5v-1A1.5 1.5 0 0 0 9.5 0h-3z"/>
            </svg>
        `;
    copyBtn.addEventListener('click', (e) => {
      e.preventDefault();
      const messageDiv = e.target.closest('.ai-chat-message');
      const contentDiv = messageDiv.querySelector('.ai-chat-message-content');
      const content = contentDiv ? contentDiv.textContent : '';
      this.copyMessageToClipboard(content, copyBtn);
    });

    const regenBtn = document.createElement('button');
    regenBtn.className = 'ai-chat-message-action';
    regenBtn.title = this.lang.regenerateResponseTitle;
    regenBtn.innerHTML = `
            <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" focusable="false">
                <path fill-rule="evenodd" d="M8 3a5 5 0 1 0 4.546 2.914.5.5 0 0 1 .908-.417A6 6 0 1 1 8 2v1z"/>
                <path d="M8 4.466V.534a.25.25 0 0 1 .41-.192l2.36 1.966c.12.1.12.284 0 .384L8.41 4.658A.25.25 0 0 1 8 4.466z"/>
            </svg>
        `;
    regenBtn.addEventListener('click', (e) => {
      e.preventDefault();
      const messageDiv = e.target.closest('.ai-chat-message');
      this.regenerateResponse(messageDiv);
    });

    actionsDiv.appendChild(copyBtn);
    actionsDiv.appendChild(regenBtn);

    return actionsDiv;
  }

  /**
   * Detect source type from filename
   *
   * @param {string} filename - Source filename
   * @returns {string} Source type: 'pdf', 'web', 'wiki', 'doc', 'image', 'other'
   */
  detectSourceType(filename, url = null) {
    if (url) return 'web';
    if (!filename) return 'other';
    const lower = filename.toLowerCase();

    if (lower.endsWith('.pdf')) return 'pdf';
    if (lower.includes('wiki') || lower.includes('wikipedia')) return 'wiki';
    if (lower.startsWith('http') || lower.includes('www.') || lower.endsWith('.html') || lower.endsWith('.htm')) return 'web';
    if (lower.endsWith('.doc') || lower.endsWith('.docx') || lower.endsWith('.odt')) return 'doc';
    if (lower.endsWith('.png') || lower.endsWith('.jpg') || lower.endsWith('.jpeg') || lower.endsWith('.gif') || lower.endsWith('.webp')) return 'image';
    if (lower.endsWith('.txt') || lower.endsWith('.md') || lower.endsWith('.csv')) return 'text';

    return 'other';
  }

  /**
   * Get icon SVG for source type
   *
   * @param {string} type - Source type
   * @returns {string} SVG HTML
   */
  getSourceTypeIcon(type) {
    const icons = {
      pdf: '<svg viewBox="0 0 16 16" fill="currentColor"><path d="M4 0a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V4.5L9.5 0H4zm0 1h5v3.5A1.5 1.5 0 0 0 10.5 6H13v8a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1z"/><path d="M4.5 11.5a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 0 1H5v1h1a.5.5 0 0 1 0 1H5a.5.5 0 0 1-.5-.5v-2zm3 0a.5.5 0 0 1 .5-.5h1a1 1 0 0 1 0 2H8.5v.5a.5.5 0 0 1-1 0v-2z"/></svg>',
      web: '<svg viewBox="0 0 16 16" fill="currentColor"><path d="M0 8a8 8 0 1 1 16 0A8 8 0 0 1 0 8zm7.5-6.923c-.67.204-1.335.82-1.887 1.855A7.97 7.97 0 0 0 5.145 4H7.5V1.077zM4.09 4a9.267 9.267 0 0 1 .64-1.539 6.7 6.7 0 0 1 .597-.933A7.025 7.025 0 0 0 2.255 4H4.09zm-.582 3.5c.03-.877.138-1.718.312-2.5H1.674a6.958 6.958 0 0 0-.656 2.5h2.49zM4.847 5a12.5 12.5 0 0 0-.338 2.5H7.5V5H4.847zM8.5 5v2.5h2.99a12.495 12.495 0 0 0-.337-2.5H8.5zM4.51 8.5a12.5 12.5 0 0 0 .337 2.5H7.5V8.5H4.51zm3.99 0V11h2.653c.187-.765.306-1.608.338-2.5H8.5zM5.145 12c.138.386.295.744.468 1.068.552 1.035 1.218 1.65 1.887 1.855V12H5.145zm.182 2.472a6.696 6.696 0 0 1-.597-.933A9.268 9.268 0 0 1 4.09 12H2.255a7.024 7.024 0 0 0 3.072 2.472zM3.82 11a13.652 13.652 0 0 1-.312-2.5h-2.49c.062.89.291 1.733.656 2.5H3.82zm6.853 3.472A7.024 7.024 0 0 0 13.745 12H11.91a9.27 9.27 0 0 1-.64 1.539 6.688 6.688 0 0 1-.597.933zM8.5 12v2.923c.67-.204 1.335-.82 1.887-1.855.173-.324.33-.682.468-1.068H8.5zm3.68-1h2.146c.365-.767.594-1.61.656-2.5h-2.49a13.65 13.65 0 0 1-.312 2.5zm2.802-3.5a6.959 6.959 0 0 0-.656-2.5H12.18c.174.782.282 1.623.312 2.5h2.49zM11.27 2.461c.247.464.462.98.64 1.539h1.835a7.024 7.024 0 0 0-3.072-2.472c.218.284.418.598.597.933zM10.855 4a7.966 7.966 0 0 0-.468-1.068C9.835 1.897 9.17 1.282 8.5 1.077V4h2.355z"/></svg>',
      wiki: '<svg viewBox="0 0 16 16" fill="currentColor"><path d="M8 1a7 7 0 1 0 0 14A7 7 0 0 0 8 1zM0 8a8 8 0 1 1 16 0A8 8 0 0 1 0 8z"/><path d="M6.5 4.5a.5.5 0 0 1 .5.5v2.5h2V5a.5.5 0 0 1 1 0v6a.5.5 0 0 1-1 0V8h-2v3a.5.5 0 0 1-1 0V5a.5.5 0 0 1 .5-.5z"/></svg>',
      doc: '<svg viewBox="0 0 16 16" fill="currentColor"><path d="M4 0a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V4.5L9.5 0H4zm0 1h5v3.5A1.5 1.5 0 0 0 10.5 6H13v8a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1z"/><path d="M4.5 8a.5.5 0 0 1 .5-.5h6a.5.5 0 0 1 0 1H5a.5.5 0 0 1-.5-.5zm0 2a.5.5 0 0 1 .5-.5h6a.5.5 0 0 1 0 1H5a.5.5 0 0 1-.5-.5zm0 2a.5.5 0 0 1 .5-.5h3a.5.5 0 0 1 0 1H5a.5.5 0 0 1-.5-.5z"/></svg>',
      image: '<svg viewBox="0 0 16 16" fill="currentColor"><path d="M6.002 5.5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0z"/><path d="M2.002 1a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V3a2 2 0 0 0-2-2h-12zm12 1a1 1 0 0 1 1 1v6.5l-3.777-1.947a.5.5 0 0 0-.577.093l-3.71 3.71-2.66-1.772a.5.5 0 0 0-.63.062L1.002 12V3a1 1 0 0 1 1-1h12z"/></svg>',
      text: '<svg viewBox="0 0 16 16" fill="currentColor"><path d="M4.5 11a.5.5 0 0 1 0-1h7a.5.5 0 0 1 0 1h-7zm0-2a.5.5 0 0 1 0-1h7a.5.5 0 0 1 0 1h-7zm0-2a.5.5 0 0 1 0-1h7a.5.5 0 0 1 0 1h-7zm0-2a.5.5 0 0 1 0-1h7a.5.5 0 0 1 0 1h-7z"/><path d="M2 2a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V2zm10-1H4a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1z"/></svg>',
      other: '<svg viewBox="0 0 16 16" fill="currentColor"><path d="M4 0a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V4.5L9.5 0H4zm0 1h5v3.5A1.5 1.5 0 0 0 10.5 6H13v8a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1z"/></svg>',
    };
    return icons[type] || icons.other;
  }

  /**
   * Get label for source type
   *
   * @param {string} type - Source type
   * @returns {string} Label
   */
  getSourceTypeLabel(type) {
    const labels = {
      pdf: 'PDF',
      web: 'Web',
      wiki: 'Wiki',
      doc: 'Doc',
      image: 'Bild',
      text: 'Text',
      other: 'Datei',
    };
    return labels[type] || labels.other;
  }

  /**
   * Render sources row with type bubbles and collapsible section
   *
   * @param {Array} sources - Array of source objects
   * @returns {HTMLElement} Sources row element
   */
  renderSourcesRow(sources) {
    const sourcesId = `src-${Date.now()}`;

    const typeCount = {};
    sources.forEach((source) => {
      const type = this.detectSourceType(source.filename, source.url);
      typeCount[type] = (typeCount[type] || 0) + 1;
    });
    const uniqueTypes = Object.keys(typeCount).slice(0, 3);

    const row = document.createElement('div');
    row.className = 'ai-chat-sources-row';

    const btn = document.createElement('button');
    btn.className = 'ai-chat-sources-toggle';
    btn.dataset.sourcesId = sourcesId;

    let bubblesHtml = '<div class="ai-chat-sources-bubbles">';
    uniqueTypes.forEach((type, i) => {
      bubblesHtml += `<span class="ai-chat-source-bubble" data-type="${type}" style="z-index: ${10 - i};">${this.getSourceTypeIcon(type)}</span>`;
    });
    bubblesHtml += '</div>';

    const typeLabels = uniqueTypes.map((t) => `<span class="ai-chat-source-type-label">${this.getSourceTypeLabel(t)}</span>`).join('');

    btn.innerHTML = `
            ${bubblesHtml}
            <div class="ai-chat-sources-info">
                <div class="ai-chat-sources-types">${typeLabels}</div>
                <span class="ai-chat-sources-count">${sources.length} ${sources.length === 1 ? 'Quelle' : 'Quellen'}</span>
            </div>
            <svg class="ai-chat-sources-chevron" viewBox="0 0 16 16" fill="currentColor"><path fill-rule="evenodd" d="M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z"/></svg>
        `;

    const sourcesSection = document.createElement('div');
    sourcesSection.className = 'ai-chat-sources-section';
    sourcesSection.dataset.sourcesId = sourcesId;

    // Merge sources with the same file name and combine their pages
    const deduped = [];
    const origToDedup = {}; // original index => merged index (both 1-based)

    sources.forEach((source, origIdx) => {
      const existing = deduped.findIndex((d) => d.filename === source.filename);
      if (existing >= 0) {
        const merged = new Set([...(deduped[existing].pages || []), ...(source.pages || [])]);
        deduped[existing].pages = [...merged].sort((a, b) => a - b);
        origToDedup[origIdx + 1] = existing + 1;
      } else {
        deduped.push({ ...source, pages: [...(source.pages || [])] });
        origToDedup[origIdx + 1] = deduped.length;
      }
    });

    const countEl = btn.querySelector('.ai-chat-sources-count');
    if (countEl) {
      countEl.textContent = `${deduped.length} ${deduped.length === 1 ? 'Quelle' : 'Quellen'}`;
    }

    const sourcesList = document.createElement('div');
    sourcesList.className = 'ai-chat-sources-list';

    deduped.forEach((source, dedupIdx) => {
      const type = this.detectSourceType(source.filename, source.url);
      const item = document.createElement('div');
      item.className = 'ai-chat-source-item';
      item.id = `${sourcesId}-source-dedup-${dedupIdx + 1}`;
      item.dataset.type = type;

      let pageInfo = '';
      if (source.pages && source.pages.length > 0) {
        pageInfo = `<span class="ai-chat-source-pages">S. ${this.escapeHtml(source.pages.join(', '))}</span>`;
      }

      const nameHtml = this.safeUrl(source.url)
        ? `<a class="ai-chat-source-link" href="${this.escapeHtml(source.url)}" target="_blank" rel="noopener noreferrer">${this.escapeHtml(source.filename)}</a>`
        : `<span class="ai-chat-source-name">${this.escapeHtml(source.filename)}</span>`;

      const downloadBtn = this.safeUrl(source.download_url)
        ? `<a class="ai-chat-source-download" href="${this.escapeHtml(source.download_url)}" target="_blank" rel="noopener noreferrer" title="Herunterladen" aria-label="Datei herunterladen"><svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5z"/><path d="M7.646 11.854a.5.5 0 0 0 .708 0l3-3a.5.5 0 0 0-.708-.708L8.5 10.293V1.5a.5.5 0 0 0-1 0v8.793L5.354 8.146a.5.5 0 1 0-.708.708l3 3z"/></svg></a>`
        : '';

      item.innerHTML = `
                <span class="ai-chat-source-number">${dedupIdx + 1}</span>
                <span class="ai-chat-source-icon" data-type="${type}">${this.getSourceTypeIcon(type)}</span>
                ${nameHtml}
                ${pageInfo}
                ${downloadBtn}
            `;

      // Anchors for all original indices of this item, so that the chips
      // can still find it via getElementById
      Object.entries(origToDedup).forEach(([origIdx, dIdx]) => {
        if (parseInt(dIdx, 10) === dedupIdx + 1) {
          const anchor = document.createElement('span');
          anchor.id = `${sourcesId}-source-${origIdx}`;
          anchor.style.cssText = 'position:absolute;width:0;height:0;overflow:hidden;pointer-events:none;';
          item.appendChild(anchor);
        }
      });

      sourcesList.appendChild(item);
    });

    sourcesSection.appendChild(sourcesList);

    btn.addEventListener('click', (e) => {
      e.preventDefault();
      const isOpen = sourcesSection.classList.contains('open');
      sourcesSection.classList.toggle('open', !isOpen);
      btn.classList.toggle('active', !isOpen);
    });

    row.appendChild(btn);
    row.appendChild(sourcesSection);

    row.sourcesData = sources;
    row.dataset.sourcesId = sourcesId;
    // Number of the file in the source list for each cited source (both 1-based)
    row.sourceFileNumbers = origToDedup;

    return row;
  }

  /**
   * Replace the citation placeholders of an answer with source chips
   *
   * A placeholder citing several sources ([1, 2] or [1][2]) becomes one chip with
   * the first source and the number of further sources. Placeholders without a
   * matching source are shown as the original text.
   *
   * @param {HTMLElement} contentEl - Element with the rendered answer
   * @param {HTMLElement} sourcesRow - Sources row element
   * @param {Array} sources - Sources array
   * @param {boolean} [withTooltips=true] - Tooltips with file name, pages and excerpt
   */
  convertFootnotesToChips(contentEl, sourcesRow, sources, withTooltips = true) {
    if (!contentEl || !sourcesRow || !sources || sources.length === 0) return;

    const { sourcesId } = sourcesRow.dataset;
    const self = this;

    /**
     * Source list entry of a source number; may be an anchor inside a merged entry
     *
     * @param {number} num - Source number (1-based)
     * @returns {HTMLElement|null}
     */
    function findSourceItem(num) {
      const anchor = document.getElementById(`${sourcesId}-source-${num}`);
      if (!anchor) return null;
      return anchor.classList.contains('ai-chat-source-item')
        ? anchor
        : (anchor.closest('.ai-chat-source-item') || anchor);
    }

    /**
     * Create a source chip
     *
     * The chip shows the type icon and the number of the file in the source list;
     * the file name is shown on hover or focus. Several cited files are shown as
     * the first one and the number of further files.
     *
     * @param {number[]} nums - Cited source numbers (1-based), all existing
     * @returns {HTMLElement}
     */
    function createSourceChip(nums) {
      const fileNumbers = sourcesRow.sourceFileNumbers || {};
      const files = [...new Set(nums.map((num) => fileNumbers[num] || num))];
      const sourceData = sources[nums[0] - 1];

      const chip = document.createElement('span');
      chip.className = 'ai-chat-source-chip';
      chip.dataset.sourceIndex = nums.join(',');
      chip.setAttribute('role', 'button');
      chip.tabIndex = 0;

      const type = self.detectSourceType(sourceData.filename, sourceData.url);
      let more = '';
      let label = `${files[0]}: ${sourceData.filename}`;
      if (files.length > 1) {
        const moreLabel = files.length === 2
          ? self.lang.citationMoreSource
          : self.lang.citationMoreSources.replace('%s', files.length - 1);
        more = `<span class="ai-chat-chip-more" aria-hidden="true">+${files.length - 1}</span>`;
        label += `, ${moreLabel}`;
      }
      chip.setAttribute('aria-label', label);
      chip.innerHTML = `<span class="ai-chat-chip-icon" data-type="${type}" aria-hidden="true">${self.getSourceTypeIcon(type)}</span>`
        + `<span class="ai-chat-chip-number" aria-hidden="true">${files[0]}</span>`
        + `<span class="ai-chat-chip-name" aria-hidden="true"><span class="ai-chat-chip-text">${self.escapeHtml(sourceData.filename)}</span></span>${more}`;

      const prepareUnfold = () => self.prepareChipUnfold(chip);
      chip.addEventListener('mouseenter', prepareUnfold);
      chip.addEventListener('focus', prepareUnfold);

      if (withTooltips) {
        self.addSourceInfoTooltip(chip, nums.map((num) => sources[num - 1]));
      }

      const openSources = (e) => {
        e.preventDefault();
        const section = sourcesRow.querySelector('.ai-chat-sources-section');
        const btn = sourcesRow.querySelector('.ai-chat-sources-toggle');
        if (section && btn) {
          section.classList.add('open');
          btn.classList.add('active');
        }
        const items = nums.map(findSourceItem).filter((item) => item !== null);
        if (items.length > 0) {
          items[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        items.forEach((item) => {
          item.classList.add('highlighted');
          setTimeout(() => item.classList.remove('highlighted'), 2000);
        });
      };
      chip.addEventListener('click', openSources);
      chip.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          openSources(e);
        }
      });

      return chip;
    }

    contentEl.querySelectorAll('.ai-chat-cite').forEach((marker) => {
      const nums = [...new Set(marker.dataset.cite.split(',').map((n) => parseInt(n, 10)))]
        .filter((num) => num > 0 && sources[num - 1]);
      if (nums.length > 0) {
        marker.replaceWith(createSourceChip(nums));
      } else {
        marker.replaceWith(document.createTextNode(marker.textContent));
      }
    });
  }
}

function initAIChatComponents() {
  debug('AIChatPageComponent: Initializing components...');

  const containers = document.querySelectorAll('.ai-chat-container');
  containers.forEach((container) => {
    if (container.id) {
      debug('AIChatPageComponent: Initializing container:', container.id);
      new AIChatPageComponent(container.id);
    }
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initAIChatComponents);
} else {
  // DOM already loaded: short delay, so that the page is complete
  const DOM_READY_DELAY = 50;
  setTimeout(initAIChatComponents, DOM_READY_DELAY);
}

/**
 * Language strings of the first chat on the page
 */
function getAIChatLang() {
  const firstChatContainer = document.querySelector('.ai-chat-container');
  if (firstChatContainer) {
    return {
      messageCopied: firstChatContainer.dataset.messageCopied || 'Copied!',
      messageCopyFailed: firstChatContainer.dataset.messageCopyFailed || 'Failed to copy',
    };
  }
  return {
    messageCopied: 'Copied!',
    messageCopyFailed: 'Failed to copy',
  };
}

function copyCodeToClipboard(button) {
  const codeBlock = button.closest('.ai-chat-code-block');
  const codeContent = codeBlock.querySelector('.ai-chat-code-content code');
  copyTextToClipboard(codeContent.textContent, button);
}

/**
 * Copy text and show the result on the button
 *
 * @param {string} text
 * @param {HTMLElement} button
 */
function copyTextToClipboard(text, button) {
  if (window.navigator.clipboard && window.isSecureContext) {
    window.navigator.clipboard.writeText(text).then(() => {
      showCodeCopyFeedback(button, getAIChatLang().messageCopied);
    }).catch(() => {
      fallbackCopyToClipboard(text, button);
    });
  } else {
    fallbackCopyToClipboard(text, button);
  }
}

function fallbackCopyToClipboard(text, button) {
  const textArea = document.createElement('textarea');
  textArea.value = text;
  textArea.style.position = 'fixed';
  textArea.style.left = '-999999px';
  textArea.style.top = '-999999px';
  document.body.appendChild(textArea);
  textArea.focus();
  textArea.select();

  try {
    document.execCommand('copy');
    showCodeCopyFeedback(button, getAIChatLang().messageCopied);
  } catch (err) {
    showCodeCopyFeedback(button, getAIChatLang().messageCopyFailed);
  }

  document.body.removeChild(textArea);
}

function showCodeCopyFeedback(button, message) {
  const originalContent = button.innerHTML;
  button.innerHTML = message;
  button.disabled = true;

  setTimeout(() => {
    button.innerHTML = originalContent;
    button.disabled = false;
  }, 1500);
}
