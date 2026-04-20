// DubSync JavaScript - Consolidated
let currentProjectId = null;
let currentSegments = null;  // Store segments data globally
let progressInterval = null;  // Store progress interval ID
let originalSegments = null;  // Store original segments before translation
let currentSegmentsMode = 'original'; // 'original' | 'translated'

// Initialize all event listeners when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    console.log('DubSync: DOM Content Loaded');
    
    try {
        // Initialize all button listeners
        initProcessYoutube();
        initLazyYouTubeEmbed();
        initTranslate();
        initClearTranslation();
        initConvertNumbersToWords();
        initSelectionMove();
        initBulkFixSelectedSegments();  // Initialize segment selection/save
        initGenerateTTS();
        initAlignTiming();
        initMergeAudio();
        initExport();
        initDeleteProject();
        initViewProject();
        initSegmentTabs();  // Initialize tab switching
        initChannelReference();
        
        console.log('DubSync: All listeners initialized successfully');
    } catch (error) {
        console.error('DubSync: Error initializing listeners', error);
    }
});

// Lazy-load YouTube iframe to avoid third-party event listener warnings
function initLazyYouTubeEmbed() {
    const container = document.getElementById('youtubeLazyPlayer');
    const loadBtn = document.getElementById('loadYoutubeBtn');
    if (!container || !loadBtn) return;

    const videoId = container.dataset.videoId;
    if (!videoId) return;

    const loadIframe = () => {
        const iframe = document.createElement('iframe');
        iframe.width = '100%';
        iframe.height = '100%';
        iframe.src = `https://www.youtube.com/embed/${videoId}`;
        iframe.frameBorder = '0';
        iframe.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture';
        iframe.allowFullscreen = true;
        iframe.loading = 'lazy';

        container.innerHTML = '';
        container.appendChild(iframe);
    };

    loadBtn.addEventListener('click', (e) => {
        e.preventDefault();
        loadIframe();
    }, { passive: true });
}

// Segment selection + save controls
function initBulkFixSelectedSegments() {
    const selectAll = document.getElementById('selectAllSegments');
    const saveBtn = document.getElementById('saveSegmentsBtn');

    console.log('[initBulkFixSelectedSegments] Initializing:', { selectAll: !!selectAll, saveBtn: !!saveBtn });

    if (!selectAll && !saveBtn) return;

    if (selectAll) {
        selectAll.addEventListener('change', () => {
            document.querySelectorAll('.segment-select').forEach((checkbox) => {
                checkbox.checked = selectAll.checked;
            });
        });
    }

    // Save segments handler
    if (saveBtn) {
        saveBtn.addEventListener('click', async (e) => {
            e.preventDefault();
            console.log('[saveBtn.click] Save clicked, projectId:', currentProjectId);
            
            if (!currentProjectId) {
                alert('Lỗi: Không tìm thấy Project ID');
                return;
            }

            try {
                saveBtn.disabled = true;
                saveBtn.textContent = 'Đang lưu...';

                const segments = collectSegments();
                console.log('[saveBtn.click] Segments to save:', segments.length);

                const styleInstruction = document.getElementById('ttsStyleInstruction')?.value || '';
                const ttsProvider = (typeof currentTtsProvider !== 'undefined' && currentTtsProvider)
                    ? currentTtsProvider
                    : (window.projectData?.tts_provider || null);
                const audioMode = (typeof currentAudioMode !== 'undefined' && currentAudioMode)
                    ? currentAudioMode
                    : (window.projectData?.audio_mode || null);
                const speakersConfigPayload = (typeof speakersConfig !== 'undefined' && speakersConfig)
                    ? speakersConfig
                    : (window.projectData?.speakers_config || null);

                const isTranslatedMode = (typeof currentSegmentsMode !== 'undefined' && currentSegmentsMode === 'translated');
                const payload = {
                    tts_provider: ttsProvider,
                    audio_mode: audioMode,
                    speakers_config: speakersConfigPayload,
                    style_instruction: styleInstruction
                };

                if (isTranslatedMode) {
                    payload.translated_segments = segments;
                } else {
                    payload.segments = segments;
                }

                const response = await fetch(`/dubsync/projects/${currentProjectId}/save-segments`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify(payload)
                });

                const data = await response.json();
                console.log('[saveBtn.click] Save response:', data);

                if (!response.ok || !data.success) {
                    throw new Error(data.error || 'Không thể lưu segments');
                }

                alert('Đã lưu thành công ' + segments.length + ' đoạn!');
            } catch (error) {
                console.error('[saveBtn.click] Error:', error);
                alert('Lỗi: ' + error.message);
            } finally {
                saveBtn.disabled = false;
                saveBtn.textContent = '💾 Save Segments';
            }
        });
    }
}

// Process YouTube
function initProcessYoutube() {
    const processBtn = document.getElementById('processYoutubeBtn');
    if (!processBtn) return;

    processBtn.addEventListener('click', async function() {
        const youtubeUrl = document.getElementById('youtubeUrl').value;
        const youtubeChannelId = document.getElementById('youtubeChannelId')?.value || null;
        if (!youtubeUrl) {
            alert('Vui lòng nhập YouTube URL');
            return;
        }

        console.log('processYoutubeBtn: YouTube URL nhận được:', youtubeUrl);
        console.log('processYoutubeBtn: URL validation...');

        toggleButton('processYoutubeBtn', true);
        updateStep('step1', 'processing');
        showProgressSection();

        // Show progress bar
        const progressContainer = document.getElementById('step1-progress');
        const progressBar = document.getElementById('step1-progress-bar');
        const progressText = document.getElementById('step1-progress-text');
        if (progressContainer) progressContainer.classList.remove('hidden');

        const fetchStartTime = Date.now();
        const maxTime = 120; // 2 minutes
        let currentProgress = 0;

        const timeCounter = setInterval(() => {
            const elapsed = Math.floor((Date.now() - fetchStartTime) / 1000);

            // Update hoặc tạo phần tử hiển thị thời gian trong Processing Status section
            let timerDisplay = document.getElementById('transcriptTimer');
            if (!timerDisplay) {
                timerDisplay = document.createElement('div');
                timerDisplay.id = 'transcriptTimer';
                timerDisplay.className = 'text-sm font-medium text-blue-700 bg-blue-50 p-3 rounded border-l-4 border-blue-500 mb-3';

                const timerContainer = document.getElementById('transcriptTimerContainer');
                if (timerContainer) {
                    timerContainer.appendChild(timerDisplay);
                }
            }

            if (timerDisplay) {
                timerDisplay.innerHTML = `<strong>⏱️ Đang lấy transcript từ YouTube...</strong><br><span class="text-sm text-gray-600">Thời gian: <strong>${elapsed}</strong> giây</span>`;
            }

            // Update progress bar (simulated progress)
            if (elapsed < maxTime) {
                // Slow down as we approach 100%
                const targetProgress = Math.min(95, (elapsed / maxTime) * 100);
                currentProgress += (targetProgress - currentProgress) * 0.1;

                if (progressBar) progressBar.style.width = currentProgress + '%';
                if (progressText) progressText.textContent = Math.floor(currentProgress) + '%';
            }
        }, 1000);

        try {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 600000);

            console.log('processYoutubeBtn: Gửi request tới /dubsync/process-youtube...');
            const payload = { youtube_url: youtubeUrl };
            if (youtubeChannelId) {
                payload.youtube_channel_id = youtubeChannelId;
            }

            console.log('processYoutubeBtn: Request body:', payload);

            const response = await fetch('/dubsync/process-youtube', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify(payload),
                signal: controller.signal
            });

            clearTimeout(timeoutId);
            clearInterval(timeCounter);

            console.log('processYoutubeBtn: Nhận response, status:', response.status);

            if (!response.ok) {
                const errorText = await response.text();
                console.error('processYoutubeBtn: HTTP Error', response.status, errorText);
                throw new Error(`HTTP ${response.status}: ${errorText}`);
            }

            const data = await response.json();
            console.log('processYoutubeBtn: Response data:', data);

            if (data.success) {
                if (progressBar) progressBar.style.width = '100%';
                if (progressText) progressText.textContent = '100%';

                const timerDisplay = document.getElementById('transcriptTimer');
                if (timerDisplay) timerDisplay.remove();

                currentProjectId = data.project_id;
                updateStep('step1', 'completed');

                if (data.metadata) {
                    displayYouTubeMetadata(youtubeUrl, data.metadata);
                }

                if (data.processing_complete === true) {
                    displaySegments(data.segments);
                    showSegmentsEditor();
                    hideAIProcessingStatus();
                } else {
                    showAIProcessingStatus();
                    pollAIProgress(data.project_id);
                }

                setTimeout(() => {
                    if (progressContainer) progressContainer.classList.add('hidden');
                }, 1500);
            } else {
                console.error('processYoutubeBtn: Lỗi từ server:', data.error);
                updateStep('step1', 'error');
                if (progressContainer) progressContainer.classList.add('hidden');

                const timerDisplay = document.getElementById('transcriptTimer');
                if (timerDisplay) timerDisplay.remove();

                alert('Lỗi: ' + data.error);
            }
        } catch (error) {
            clearInterval(timeCounter);
            updateStep('step1', 'error');
            if (progressContainer) progressContainer.classList.add('hidden');

            const timerDisplay = document.getElementById('transcriptTimer');
            if (timerDisplay) timerDisplay.remove();

            console.error('YouTube process error:', error);
            console.error('Error details - Name:', error.name, 'Message:', error.message);

            if (error.name === 'AbortError') {
                console.warn('processYoutubeBtn: Request timeout');
                alert('Timeout: Việc xử lý transcript quá lâu (>10 phút). Vui lòng thử lại hoặc kiểm tra kết nối internet.');
            } else {
                console.warn('processYoutubeBtn: Connection error');
                alert('Lỗi kết nối: ' + error.message);
            }
        } finally {
            console.log('processYoutubeBtn: Hoàn thành xử lý');
            toggleButton('processYoutubeBtn', false);
        }
    });
}

// Translate segments
function initTranslate() {
    const translateBtn = document.getElementById('translateBtn');
    if (!translateBtn) {
        // Button not shown for this project status - this is expected
        return;
    }

    console.log('Initializing translate button');

    translateBtn.addEventListener('click', async function(e) {
        e.preventDefault();

        if (!currentProjectId) {
            console.error('No project ID available');
            alert('Lỗi: Không tìm thấy Project ID');
            return;
        }

        console.log('Translate button clicked, projectId:', currentProjectId);

        toggleButton('translateBtn', true);
        updateStep('step2', 'processing');
        const segments = collectSegments();

        console.log('Segments to translate:', segments);

        if (!segments || segments.length === 0) {
            console.error('No segments found to translate');
            alert('Lỗi: Không tìm thấy đoạn để dịch');
            toggleButton('translateBtn', false);
            return;
        }

        const providerSelect = document.getElementById('translationProvider');
        const provider = providerSelect ? providerSelect.value : 'google';
        const styleSelect = document.getElementById('translationStyle');
        const style = styleSelect ? styleSelect.value : 'default';
        console.log('Selected translation provider:', provider, 'style:', style);

        showTranslationProgress(0);

        try {
            const endpoint = `/dubsync/projects/${currentProjectId}/translate`;
            console.log('Sending request to:', endpoint);

            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({
                    segments,
                    provider,
                    style
                })
            });

            console.log('Response status:', response.status);

            const data = await response.json();
            console.log('Response data:', data);

            if (data.success) {
                showTranslationProgress(100);

                setTimeout(() => {
                    updateStep('step2', 'completed');
                    displayTranslatedSegments(data.translated_segments);
                        const translatedTextarea = document.getElementById('translatedFullTranscriptContent');
                        if (translatedTextarea && Array.isArray(data.translated_segments)) {
                            const fullText = data.translated_segments
                                .map(segment => segment.text || '')
                                .filter(text => text.trim() !== '')
                                .join('\n');
                            translatedTextarea.value = fullText;
                            if (typeof updateTranscriptWordCounts === 'function') {
                                updateTranscriptWordCounts();
                            }
                        }
                    if (confirm('Bạn có muốn lưu nội dung vừa dịch này không?')) {
                        saveSegmentsToDatabase(data.translated_segments);
                    }
                    document.getElementById('generateTTSBtn').classList.remove('hidden');
                    hideTranslationProgress();
                }, 500);
            } else {
                updateStep('step2', 'error');
                hideTranslationProgress();
                console.error('Translation error:', data.error);
                alert('Lỗi dịch: ' + (data.error || 'Lỗi không xác định'));
            }
        } catch (error) {
            updateStep('step2', 'error');
            hideTranslationProgress();
            console.error('Translation fetch error:', error);
            alert('Lỗi kết nối: ' + error.message);
        } finally {
            toggleButton('translateBtn', false);
        }
    });
}

// Clear/Reset translated content
function initClearTranslation() {
    const clearBtn = document.getElementById('clearTranslationBtn');
    if (!clearBtn) return;
    
    clearBtn.addEventListener('click', function(e) {
        e.preventDefault();
        
        if (!currentSegments || currentSegments.length === 0) {
            alert('Không có nội dung để xóa');
            return;
        }
        
        if (confirm('Bạn có chắc chắn muốn xóa nội dung đã dịch? Bạn sẽ có thể dịch lại sau.')) {
            // Reset segments to original English text
            const segmentsList = document.getElementById('segmentsList');
            if (segmentsList) {
                segmentsList.innerHTML = '';
                
                currentSegments.forEach((segment, index) => {
                    const segmentDiv = document.createElement('div');
                    segmentDiv.className = 'bg-white border border-gray-200 rounded-lg p-4 relative';
                    const startTime = segment.start_time ?? segment.start ?? 0;
                    const endTime = segment.end_time ?? (startTime + (segment.duration ?? 0));
                    const duration = segment.duration || 0;
                    
                    // Use original_text if available, otherwise original text
                    const displayText = segment.original_text || segment.text || '';
                    
                    segmentDiv.innerHTML = `
                        <div class="flex justify-between items-start mb-2">
                            <span class="text-sm font-medium text-gray-600">Đoạn ${index + 1} (${startTime.toFixed ? startTime.toFixed(2) : startTime}s - ${endTime.toFixed ? endTime.toFixed(2) : endTime}s)</span>
                            <span class="text-xs text-gray-500">${duration.toFixed ? duration.toFixed(2) : duration}s</span>
                        </div>
                        <textarea 
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-red-500 focus:border-transparent segment-text"
                            rows="2"
                            data-index="${index}"
                        >${displayText}</textarea>
                        <button class="move-selection-btn hidden absolute top-2 right-2 bg-gray-800 text-white text-xs px-2 py-1 rounded shadow" data-index="${index}" title="Chuyển đoạn đã chọn sang đầu đoạn tiếp theo">
                            ↪
                        </button>
                    `;
                    segmentsList.appendChild(segmentDiv);
                });

                attachSelectionMoveHandlers();
                
                alert('Nội dung đã xóa. Bạn có thể chỉnh sửa và dịch lại.');
                console.log('Translation cleared');
            }
        }
    });
}

// Fix original paragraph segmentation
function initFixOriginalParagraph() {
    const fixBtn = document.getElementById('fixOriginalParagraphBtn');
    if (!fixBtn) return;

    fixBtn.addEventListener('click', function(e) {
        e.preventDefault();

        if (!currentSegments || currentSegments.length === 0) {
            alert('Không có nội dung để xử lý');
            return;
        }

        const confirmed = confirm('Hệ thống sẽ gộp lại đoạn gốc theo câu đầy đủ và cập nhật lại timeline. Bản dịch hiện tại sẽ bị reset. Tiếp tục?');
        if (!confirmed) return;

        // Show processing indicator (don't block UI)
        fixBtn.disabled = true;
        fixBtn.textContent = 'Đang xử lý...';

        // Defer heavy computation to next frame using requestAnimationFrame
        requestAnimationFrame(() => {
            // Process in next microtask to ensure smooth UI
            setTimeout(() => {
                try {
                    const fixedSegments = mergeSegmentsIntoSentences(currentSegments);
                    currentSegments = fixedSegments;

                    displaySegments(fixedSegments);
                    showSegmentsEditor();

                    alert('Đã sửa đoạn gốc và cập nhật timeline. Bạn có thể dịch lại.');
                } finally {
                    // Reset button
                    fixBtn.disabled = false;
                    fixBtn.textContent = 'Fix Original Paragraph';
                }
            }, 0);
        });
    }, { passive: false });
}

function mergeSegmentsIntoSentences(segments) {
    const merged = [];
    let bufferText = '';
    let startTime = null;
    let duration = 0;

    // Helper functions
    const normalizeText = (text) => (text || '')
        .replace(/[’‘]/g, "'")
        .replace(/\s+/g, ' ')
        .trim();
    const getDuration = (segment) => {
        if (segment.duration && segment.duration > 0) return segment.duration;
        const start = segment.start ?? segment.start_time ?? 0;
        const end = segment.end_time ?? 0;
        if (end && end > start) return end - start;
        return 0;
    };

    // Check if text ends with sentence-ending punctuation (. ! ? …)
    const hasSentenceEnd = (text) => /[.!?…]+["')\]]?\s*$/.test(text);

    // Remove trailing punctuation to detect incomplete clauses/verbs like "That's." or "let me,"
    const stripTrailingPunctuation = (text) => (text || '').replace(/[\s.,!?…:;"')\]]+$/g, '').trim();

    // RULE 1: Check if text ends with connector words
    // These words indicate the sentence continues to the next segment
    const endsWithConnector = (text) => {
        const trimmed = stripTrailingPunctuation(text).toLowerCase().trim();
        const connectors = [
            // Conjunctions - connect clauses
            'and', 'or', 'but', 'yet', 'so', 'because', 'if', 'unless', 'while', 'when',
            'after', 'before', 'since', 'than', 'nor',
            // Prepositions - show relationships
            'of', 'in', 'on', 'at', 'to', 'for', 'with', 'from', 'by', 'about', 'into',
            'through', 'during', 'between', 'among', 'within', 'without', 'under', 'over',
            'above', 'below', 'around', 'along', 'across', 'toward',
            // Articles/verbs that indicate continuation
            'the', 'a', 'an', 'as', 'is', 'are', 'was', 'were', 'be', 'been', 'being'
        ];
        const lastWord = trimmed.split(/\s+/).pop() || '';
        return connectors.includes(lastWord);
    };

    // RULE 5: Check if text ends with incomplete demonstratives/pronouns
    // These indicate the clause continues to the next segment
    // Example: "That's" → continues to "That's going to be..."
    const endsWithIncompleteClause = (text) => {
        const trimmed = stripTrailingPunctuation(text).toLowerCase().trim();
        // Match patterns like: That's/Thats, It's/Its, There's/Theres, etc.
        const incompletePatterns = [
            /that\s*'?s\s*$/,          // That's / Thats
            /it\s*'?s\s*$/,            // It's / Its
            /there\s*'?s\s*$/,         // There's / Theres
            /what\s*'?s\s*$/,          // What's / Whats
            /who\s*'?s\s*$/,           // Who's / Whos
            /which\s*'?s\s*$/,         // Which's / Whichs
            /here\s*'?s\s*$/,          // Here's / Heres
            /this\s+is\s*$/,           // This is
            /that\s+is\s*$/,           // That is
        ];
        return incompletePatterns.some((pattern) => pattern.test(trimmed));
    };

    // RULE 6: Check if text ends with incomplete verbs/actions
    // These verbs need an object or continuation
    // Example: "let me" → continues to "let me tell you..."
    const endsWithIncompleteVerb = (text) => {
        const trimmed = stripTrailingPunctuation(text).toLowerCase().trim();
        const incompleteVerbs = [
            'let', 'tell', 'say', 'ask', 'make', 'give', 'show', 'help', 'see', 'get',
            'take', 'put', 'send', 'bring', 'keep', 'leave', 'find', 'lose', 'hear',
            'watch', 'look', 'listen', 'feel', 'think', 'know', 'believe', 'want',
            'like', 'love', 'hate', 'need', 'have', 'hold', 'catch', 'throw', 'call'
        ];
        const words = trimmed.split(/\s+/).filter(Boolean);
        const lastWord = words[words.length - 1] || '';
        const lastTwo = words.slice(-2).join(' ');

        // Pattern like "let me", "tell you", "ask him", etc.
        const pronouns = ['me', 'us', 'you', 'him', 'her', 'them', 'it'];
        const lastTwoParts = lastTwo.split(' ');
        if (lastTwoParts.length === 2) {
            const [verb, pronoun] = lastTwoParts;
            if (incompleteVerbs.includes(verb) && pronouns.includes(pronoun)) {
                return true;
            }
        }

        // Single-word incomplete verb at end
        return incompleteVerbs.includes(lastWord);
    };

    // Main decision logic for segment splitting
    const shouldForceSplit = (text, wordCount, nextSegmentWordCount) => {
        // RULE 1: Never split if ends with connector
        // Example: "According to" → continues to "According to research"
        if (endsWithConnector(text)) {
            return false;
        }

        // RULE 5: Never split if ends with incomplete demonstrative/pronoun
        // Example: "That's" → continues to "That's going to be..."
        if (endsWithIncompleteClause(text)) {
            return false;
        }

        // RULE 6: Never split if ends with incomplete verb
        // Example: "let me" → continues to "let me tell you..."
        if (endsWithIncompleteVerb(text)) {
            return false;
        }

        // RULE 2: Never split if no sentence-ending punctuation
        // Incomplete sentence - must continue to complete the thought
        // Example: "As you may know" (no .) → continues
        if (!hasSentenceEnd(text)) {
            return false;
        }

        // RULE 7: Merge shorter segment into longer segment
        // If next segment is significantly longer (>1.5x), merge current to next
        if (nextSegmentWordCount && wordCount < nextSegmentWordCount * 1.5 && wordCount <= 15) {
            return false;
        }

        // RULE 3: Split if proper punctuation AND no connector AND reasonable length
        // At this point we know: has punctuation AND doesn't end with connector
        return wordCount >= 20; // Lower threshold since we have valid ending
    };

    // Process each segment
    segments.forEach((segment, index) => {
        const text = normalizeText(segment.original_text || segment.text || '');
        if (!text) return; // Skip empty segments

        if (!bufferText) {
            startTime = segment.start ?? segment.start_time ?? 0;
        }

        bufferText += (bufferText ? ' ' : '') + text;
        duration += getDuration(segment);

        const wordCount = bufferText.split(/\s+/).filter(Boolean).length;
        const isLastSegment = index === segments.length - 1;

        // Calculate next segment word count for RULE 7 (length-based merging)
        const nextSegmentText = !isLastSegment ? (segments[index + 1]?.original_text || segments[index + 1]?.text || '') : '';
        const nextSegmentWordCount = nextSegmentText.split(/\s+/).filter(Boolean).length;

        let shouldCreateSegment = false;

        if (isLastSegment) {
            // Always create segment for last item
            shouldCreateSegment = true;
        } else if (shouldForceSplit(bufferText, wordCount, nextSegmentWordCount)) {
            // Passed all rules: proper punctuation + no connector + good length
            shouldCreateSegment = true;
        } else if (wordCount >= 50) {
            // RULE 4: Safety overflow - prevent buffer from getting too large
            shouldCreateSegment = true;
        }
        // Otherwise: continue merging to next segment

        if (shouldCreateSegment && bufferText.trim()) {
            const normalized = normalizeText(bufferText);
            merged.push({
                index: merged.length,
                text: normalized,
                original_text: normalized,
                start: startTime || 0,
                start_time: startTime || 0,
                end_time: (startTime || 0) + duration,
                duration: duration
            });

            bufferText = '';
            duration = 0;
        }
    });

    return merged;
}

function showTranslationProgress(percent) {
    const progressContainer = document.getElementById('progressContainer');
    const progressBar = document.getElementById('progressBar');
    const progressPercent = document.getElementById('progressPercent');
    
    if (progressContainer && progressBar && progressPercent) {
        progressContainer.classList.remove('hidden');
        progressBar.style.width = percent + '%';
        progressPercent.textContent = percent + '%';
    }
    
    // Clear any existing interval
    if (progressInterval) {
        clearInterval(progressInterval);
        progressInterval = null;
    }
    
    // Simulate progress if not complete
    if (percent < 100) {
        let currentPercent = percent;
        progressInterval = setInterval(() => {
            currentPercent += Math.random() * 15;
            if (currentPercent >= 90) currentPercent = 90;  // Stop at 90%, wait for response
            
            if (progressBar && progressPercent) {
                progressBar.style.width = currentPercent + '%';
                progressPercent.textContent = Math.round(currentPercent) + '%';
            }
            
            if (currentPercent >= 90) {
                clearInterval(progressInterval);
                progressInterval = null;
            }
        }, 300);
    }
}

function hideTranslationProgress() {
    const progressContainer = document.getElementById('progressContainer');
    if (progressContainer) {
        setTimeout(() => {
            progressContainer.classList.add('hidden');
        }, 1000);
    }
}

// Generate TTS
function initGenerateTTS() {
    const generateTTSBtn = document.getElementById('generateTTSBtn');
    if (!generateTTSBtn) return;

    // Edit page has a dedicated selected-segments TTS flow.
    if (generateTTSBtn.dataset.ttsMode === 'selected-segments') {
        return;
    }
    
    generateTTSBtn.addEventListener('click', async function() {
        if (!currentProjectId) return;

        // On create page, redirect to edit for TTS flow
        if (!window.projectData || !window.projectData.id) {
            window.location.href = `/projects/${currentProjectId}/edit`;
            return;
        }

        toggleButton('generateTTSBtn', true);
        updateStep('step3', 'processing');

        try {
            const response = await fetch(`/dubsync/projects/${currentProjectId}/generate-tts`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                }
            });

            const data = await response.json();
            if (data.success) {
                updateStep('step3', 'completed');
                document.getElementById('alignTimingBtn').classList.remove('hidden');
            } else {
                updateStep('step3', 'error');
                alert('Lỗi: ' + data.error);
            }
        } catch (error) {
            updateStep('step3', 'error');
            alert('Lỗi kết nối: ' + error.message);
        } finally {
            toggleButton('generateTTSBtn', false);
        }
    });
}

// Align timing
function initAlignTiming() {
    const alignTimingBtn = document.getElementById('alignTimingBtn');
    if (!alignTimingBtn) return;
    
    alignTimingBtn.addEventListener('click', async function() {
        if (!currentProjectId) return;

        toggleButton('alignTimingBtn', true);
        updateStep('step4', 'processing');

        try {
            const response = await fetch(`/dubsync/projects/${currentProjectId}/align-timing`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                }
            });

            const data = await response.json();
            if (data.success) {
                updateStep('step4', 'completed');
                document.getElementById('mergeAudioBtn').classList.remove('hidden');
            } else {
                updateStep('step4', 'error');
                alert('Lỗi: ' + data.error);
            }
        } catch (error) {
            updateStep('step4', 'error');
            alert('Lỗi kết nối: ' + error.message);
        } finally {
            toggleButton('alignTimingBtn', false);
        }
    });
}

// Merge audio
function initMergeAudio() {
    const mergeAudioBtn = document.getElementById('mergeAudioBtn');
    if (!mergeAudioBtn) return;
    
    mergeAudioBtn.addEventListener('click', async function() {
        if (!currentProjectId) return;

        toggleButton('mergeAudioBtn', true);
        updateStep('step5', 'processing');

        try {
            const response = await fetch(`/dubsync/projects/${currentProjectId}/merge-audio`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                }
            });

            const data = await response.json();
            if (data.success) {
                updateStep('step5', 'completed');
                showExportSection();
            } else {
                updateStep('step5', 'error');
                alert('Lỗi: ' + data.error);
            }
        } catch (error) {
            updateStep('step5', 'error');
            alert('Lỗi kết nối: ' + error.message);
        } finally {
            toggleButton('mergeAudioBtn', false);
        }
    });
}

// Export files
function initExport() {
    const exportBtn = document.getElementById('exportBtn');
    if (!exportBtn) return;
    
    exportBtn.addEventListener('click', async function() {
        if (!currentProjectId) return;

        const formats = [];
        document.querySelectorAll('.export-format:checked').forEach(checkbox => {
            formats.push(checkbox.value);
        });

        if (formats.length === 0) {
            alert('Vui lòng chọn ít nhất một định dạng');
            return;
        }

        toggleButton('exportBtn', true);

        try {
            const response = await fetch(`/dubsync/projects/${currentProjectId}/export`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({ formats })
            });

            const data = await response.json();
            if (data.success) {
                displayDownloadLinks(data.files);
            } else {
                alert('Lỗi: ' + data.error);
            }
        } catch (error) {
            alert('Lỗi kết nối: ' + error.message);
        } finally {
            toggleButton('exportBtn', false);
        }
    });
}

// Delete project
function initDeleteProject() {
    document.querySelectorAll('.delete-project').forEach(button => {
        button.addEventListener('click', async function() {
            if (!confirm('Bạn có chắc muốn xóa dự án này?')) return;

            const projectId = this.dataset.projectId;

            try {
                const response = await fetch(`/dubsync/projects/${projectId}`, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    }
                });

                const data = await response.json();
                if (data.success) {
                    location.reload();
                } else {
                    alert('Lỗi: ' + data.error);
                }
            } catch (error) {
                alert('Lỗi kết nối: ' + error.message);
            }
        });
    });
}

// Helper functions
function toggleButton(buttonId, loading) {
    const button = document.getElementById(buttonId);
    if (!button) return;
    
    const text = document.getElementById(buttonId + 'Text');
    const loader = document.getElementById(buttonId + 'Loader');

    if (loading) {
        button.disabled = true;
        if (text) text.classList.add('hidden');
        if (loader) loader.classList.remove('hidden');
    } else {
        button.disabled = false;
        if (text) text.classList.remove('hidden');
        if (loader) loader.classList.add('hidden');
    }
}

function updateStep(stepId, status) {
    const step = document.getElementById(stepId);
    if (!step) return;
    
    const circle = step.querySelector('div');
    if (!circle) return;

    circle.classList.remove('bg-gray-300', 'bg-blue-500', 'bg-green-500', 'bg-red-500', 'animate-pulse');

    if (status === 'processing') {
        circle.classList.add('bg-blue-500', 'animate-pulse');
    } else if (status === 'completed') {
        circle.classList.add('bg-green-500');
    } else if (status === 'error') {
        circle.classList.add('bg-red-500');
    }
}

function showProgressSection() {
    const section = document.getElementById('progressSection');
    if (section) section.classList.remove('hidden');
}

function showSegmentsEditor() {
    const section = document.getElementById('segmentsEditor');
    if (section) section.classList.remove('hidden');
    
    const commandBar = document.getElementById('floatingCommandBar');
    if (commandBar) commandBar.classList.remove('hidden');
}

function showExportSection() {
    const section = document.getElementById('exportSection');
    if (section) section.classList.remove('hidden');
}

function convertFourDigitNumbersInText(text) {
    if (!text || typeof text !== 'string') return text;

    const digits = ['không', 'một', 'hai', 'ba', 'bốn', 'năm', 'sáu', 'bảy', 'tám', 'chín'];

    const readOnes = (ones, afterTens) => {
        if (ones === 0) return '';
        if (afterTens) {
            if (ones === 1) return 'mốt';
            if (ones === 4) return 'tư';
            if (ones === 5) return 'lăm';
        }
        return digits[ones];
    };

    const readTens = (tens, ones, hasHundreds) => {
        if (tens === 0) {
            if (ones === 0) return '';
            return (hasHundreds ? 'lẻ ' : '') + readOnes(ones, false);
        }
        if (tens === 1) {
            return 'mười' + (ones ? ' ' + readOnes(ones, true) : '');
        }
        return digits[tens] + ' mươi' + (ones ? ' ' + readOnes(ones, true) : '');
    };

    const readHundreds = (hundreds, tens, ones, hasThousands) => {
        let result = '';
        if (hundreds > 0) {
            result = digits[hundreds] + ' trăm';
        } else if ((tens > 0 || ones > 0) && hasThousands) {
            result = 'không trăm';
        }

        const tensPart = readTens(tens, ones, result !== '');
        if (tensPart) {
            result = result ? result + ' ' + tensPart : tensPart;
        }
        return result;
    };

    const numberToVietnamese = (num) => {
        if (num < 1000 || num > 9999) return String(num);
        const thousands = Math.floor(num / 1000);
        const hundreds = Math.floor((num % 1000) / 100);
        const tens = Math.floor((num % 100) / 10);
        const ones = num % 10;

        let result = digits[thousands] + ' ngàn';
        const hundredsPart = readHundreds(hundreds, tens, ones, true);
        if (hundredsPart) {
            result += ' ' + hundredsPart;
        }
        return result.trim();
    };

    return text.replace(/\b[1-9]\d{3}\b/g, (match) => {
        const num = parseInt(match, 10);
        if (Number.isNaN(num)) return match;
        return numberToVietnamese(num);
    });
}

function initConvertNumbersToWords() {
    const convertBtn = document.getElementById('convertNumbersToWordsBtn');
    if (!convertBtn) return;

    convertBtn.addEventListener('click', function(e) {
        e.preventDefault();

        if (!currentSegments || currentSegments.length === 0) {
            alert('Không có segments để xử lý');
            return;
        }

        if (typeof currentSegmentsMode !== 'undefined' && currentSegmentsMode !== 'translated') {
            alert('Vui lòng chuyển sang nội dung đã dịch trước khi chuyển số thành chữ.');
            return;
        }

        let changedCount = 0;
        const textareas = document.querySelectorAll('.segment-text');

        textareas.forEach((textarea) => {
            const index = parseInt(textarea.dataset.index, 10);
            const originalText = textarea.value || '';
            const convertedText = convertFourDigitNumbersInText(originalText);

            if (convertedText !== originalText) {
                textarea.value = convertedText;
                changedCount++;
                if (currentSegments && currentSegments[index]) {
                    currentSegments[index].text = convertedText;
                }
            }

            if (typeof autoResizeTextarea === 'function') {
                autoResizeTextarea(textarea);
            }
        });

        updateFullTranscriptDisplay();

        if (changedCount === 0) {
            alert('Không tìm thấy số 4 chữ số để chuyển đổi.');
            return;
        }

        if (confirm(`Đã chuyển ${changedCount} đoạn. Bạn có muốn lưu ngay không?`)) {
            saveSegmentsToDatabase(currentSegments);
        }
    });
}

function autoResizeTextarea(textarea) {
    if (!textarea) return;
    textarea.style.height = 'auto';
    textarea.style.overflow = 'hidden';
    textarea.style.height = textarea.scrollHeight + 'px';
}

function displaySegments(segments) {
    const segmentsList = document.getElementById('segmentsList');
    if (!segmentsList) return;
    
    // Store segments globally for later use
    currentSegments = segments;
    currentSegmentsMode = 'original';
    
    segmentsList.innerHTML = '';

    segments.forEach((segment, index) => {
        // Add gap indicator before segment if there's a significant gap
        if (index > 0) {
            const prevSegment = segments[index - 1];
            const prevEndTime = prevSegment.end_time ?? ((prevSegment.start_time ?? 0) + (prevSegment.duration ?? 0));
            const currentStartTime = segment.start_time ?? segment.start ?? 0;
            const gapDuration = currentStartTime - prevEndTime;
            
            if (gapDuration > 0.5) {  // Show gaps longer than 500ms
                const gapDiv = document.createElement('div');
                gapDiv.className = 'bg-yellow-50 border-l-4 border-yellow-400 p-3 mb-3 rounded text-xs text-yellow-700';
                gapDiv.innerHTML = `🔇 Khoảng nghỉ: <strong>${formatTime(gapDuration)}</strong> (${formatTime(prevEndTime)} - ${formatTime(currentStartTime)})`;
                segmentsList.appendChild(gapDiv);
            }
        }
        
        const segmentDiv = document.createElement('div');
        segmentDiv.className = 'bg-gray-50 p-3 rounded relative mb-2';
        const startTime = segment.start_time ?? segment.start ?? 0;
        const endTime = segment.end_time ?? (segment.duration ? startTime + segment.duration : startTime);
        
        segmentDiv.innerHTML = `
            <div class="flex justify-between items-start mb-2">
                <div class="flex items-center gap-2">
                    <input type="checkbox" class="segment-select" data-index="${index}">
                    <span class="text-xs font-semibold text-gray-600">
                        Đoạn ${index + 1} (${formatTime(startTime)} → ${formatTime(endTime)})
                    </span>
                </div>
            </div>
            <textarea class="w-full p-2 border rounded text-sm segment-text" data-index="${index}" rows="3">${segment.text || ''}</textarea>
        `;
        segmentsList.appendChild(segmentDiv);
    });

    attachSelectionMoveHandlers();
    initBulkFixSelectedSegments();
    
    // Update full transcript display
    updateFullTranscriptDisplay();
}

function deleteSegment(index) {
    if (!currentSegments || index < 0 || index >= currentSegments.length) return;
    
    // Remove segment from array
    currentSegments.splice(index, 1);
    
    // Re-render segments
    displayTranslatedSegments(currentSegments);
    
    // Save changes to database if in edit mode
    if (currentProjectId && typeof currentProjectId !== 'undefined' && currentProjectId > 0) {
        saveSegmentsToDatabase(currentSegments);
    }
}

function saveSegmentsToDatabase(segments) {
    try {
        const styleInstruction = document.getElementById('ttsStyleInstruction')?.value || '';
        const ttsProvider = (typeof currentTtsProvider !== 'undefined' && currentTtsProvider)
            ? currentTtsProvider
            : (window.projectData?.tts_provider || null);
        const audioMode = (typeof currentAudioMode !== 'undefined' && currentAudioMode)
            ? currentAudioMode
            : (window.projectData?.audio_mode || null);
        const speakersConfigPayload = (typeof speakersConfig !== 'undefined' && speakersConfig)
            ? speakersConfig
            : (window.projectData?.speakers_config || null);

        const isTranslatedMode = (typeof currentSegmentsMode !== 'undefined' && currentSegmentsMode === 'translated');
        const payload = {
            tts_provider: ttsProvider,
            audio_mode: audioMode,
            speakers_config: speakersConfigPayload,
            style_instruction: styleInstruction
        };

        if (isTranslatedMode) {
            payload.translated_segments = segments;
        } else {
            payload.segments = segments;
        }

        fetch(`/dubsync/projects/${currentProjectId}/save-segments`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify(payload)
        }).then(response => response.json())
         .then(data => {
            if (!data.success) {
                console.error('[saveSegmentsToDatabase] Error:', data.error);
            }
        }).catch(error => {
            console.error('[saveSegmentsToDatabase] Error:', error);
        });
    } catch (error) {
        console.error('[saveSegmentsToDatabase] Error:', error);
    }
}

function displayTranslatedSegments(segments) {
    const segmentsList = document.getElementById('segmentsList');
    if (!segmentsList) return;
    
    // Store segments globally for later use
    currentSegments = segments;
    currentSegmentsMode = 'translated';
    
    segmentsList.innerHTML = '';

    segments.forEach((segment, index) => {
        // Add gap indicator before segment if there's a significant gap
        if (index > 0) {
            const prevSegment = segments[index - 1];
            const prevEndTime = prevSegment.end_time ?? ((prevSegment.start_time ?? 0) + (prevSegment.duration ?? 0));
            const currentStartTime = segment.start_time ?? segment.start ?? 0;
            const gapDuration = currentStartTime - prevEndTime;
            
            if (gapDuration > 0.5) {  // Show gaps longer than 500ms
                const gapDiv = document.createElement('div');
                gapDiv.className = 'bg-yellow-50 border-l-4 border-yellow-400 p-3 mb-3 rounded text-xs text-yellow-700';
                gapDiv.innerHTML = `🔇 Khoảng nghỉ: <strong>${formatTime(gapDuration)}</strong> (${formatTime(prevEndTime)} - ${formatTime(currentStartTime)})`;
                segmentsList.appendChild(gapDiv);
            }
        }
        
        const segmentDiv = document.createElement('div');
        segmentDiv.className = 'bg-gray-50 p-3 rounded relative mb-2';
        const startTime = segment.start_time ?? segment.start ?? 0;
        const endTime = segment.end_time ?? (segment.duration ? startTime + segment.duration : startTime);
        
        segmentDiv.innerHTML = `
            <div class="flex justify-between items-start mb-2">
                <div class="flex items-center gap-2">
                    <input type="checkbox" class="segment-select" data-index="${index}">
                    <span class="text-xs font-semibold text-gray-600">
                        Đoạn ${index + 1} (${formatTime(startTime)} → ${formatTime(endTime)})
                    </span>
                </div>
                <button type="button" class="delete-segment px-2 py-1 bg-red-600 hover:bg-red-700 text-white rounded text-xs font-medium transition" data-index="${index}" title="Delete this segment">
                    🗑️ Delete
                </button>
            </div>
            <div class="mb-2">
                <label class="text-xs text-gray-600 font-medium">Original:</label>
                <p class="text-sm text-teal-600"><span class="px-1.5 py-0.5 rounded text-xs font-semibold text-blue-700 bg-blue-50 border border-blue-200">EN</span> ${segment.original_text || ''}</p>
            </div>
            <div>
                <label class="text-xs text-gray-600 font-medium"><span class="px-1.5 py-0.5 rounded text-xs font-semibold text-white bg-red-600">VI</span> Translated:</label>
                <textarea class="w-full p-2 border rounded text-sm segment-text" data-index="${index}" rows="3">${segment.text || ''}</textarea></textarea>
            </div>
            <button class="mt-2 text-xs text-blue-600 hover:underline regenerate-tts" data-index="${index}">
                Tạo lại giọng nói cho đoạn này
            </button>
        `;
        segmentsList.appendChild(segmentDiv);

        const textarea = segmentDiv.querySelector('.segment-text');
        if (textarea) {
            autoResizeTextarea(textarea);
            textarea.addEventListener('input', () => autoResizeTextarea(textarea));
        }
    });

    // Add delete segment event listeners
    document.querySelectorAll('.delete-segment').forEach(button => {
        button.addEventListener('click', function() {
            const index = parseInt(this.dataset.index);
            if (confirm(`Bạn có chắc muốn xóa đoạn ${index + 1}?`)) {
                deleteSegment(index);
            }
        });
    });

    document.querySelectorAll('.regenerate-tts').forEach(button => {
        button.addEventListener('click', async function() {
            const index = this.dataset.index;
            const text = document.querySelector(`.segment-text[data-index="${index}"]`)?.value;
            if (text) await regenerateSegment(index, text);
        });
    });

    attachSelectionMoveHandlers();
    initBulkFixSelectedSegments();
    
    // Update full transcript display
    updateFullTranscriptDisplay();
}

// Selection move helpers
let lastSelection = null;
let lastPointer = null;

function ensureFloatingMoveButton() {
    let button = document.getElementById('moveSelectionFloatingBtn');
    if (button) return button;

    button = document.createElement('button');
    button.id = 'moveSelectionFloatingBtn';
    button.type = 'button';
    button.className = 'hidden fixed z-50 bg-gray-800 text-white text-xs px-3 py-1 rounded shadow';
    button.textContent = 'Chuyển sang đoạn sau ↪';
    document.body.appendChild(button);
    return button;
}

function showFloatingMoveButton(index, x, y, direction) {
    const button = ensureFloatingMoveButton();
    button.dataset.index = index;
    button.dataset.direction = direction || 'next';
    button.textContent = direction === 'prev' ? 'Chuyển lên đoạn trước ↑' : 'Chuyển sang đoạn sau ↪';
    button.style.left = `${x}px`;
    button.style.top = `${y}px`;
    button.classList.remove('hidden');
}

function hideFloatingMoveButton() {
    const button = document.getElementById('moveSelectionFloatingBtn');
    if (!button) return;
    button.classList.add('hidden');
    delete button.dataset.index;
}

function initSelectionMove() {
    const button = ensureFloatingMoveButton();

    button.addEventListener('mousedown', (e) => {
        // Prevent textarea blur before we capture selection
        e.preventDefault();
        e.stopPropagation();
    });

    button.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        const index = parseInt(button.dataset.index, 10);
        if (Number.isNaN(index)) return;
        const direction = button.dataset.direction || 'next';
        moveSelectionToAdjacentSegment(index, direction, lastSelection);
        hideFloatingMoveButton();
    });

    document.addEventListener('click', (e) => {
        if (e.target.id === 'moveSelectionFloatingBtn') return;
        hideFloatingMoveButton();
    }, { passive: true });

    document.addEventListener('scroll', hideFloatingMoveButton, { passive: true });
}

function attachSelectionMoveHandlers() {
    // Debounce utility for reducing handler frequency
    const debounce = (func, wait) => {
        let timeout;
        return function executedFunction(...args) {
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(this, args), wait);
        };
    };

    document.querySelectorAll('.segment-text').forEach((textarea) => {
        if (textarea.dataset.moveHandlerAttached === 'true') return;
        textarea.dataset.moveHandlerAttached = 'true';

        // Debounce expensive DOM operations (50ms should feel immediate)
        const debouncedToggle = debounce((event) => toggleMoveButtonForSelection(textarea, event), 50);

        // Mouseup should update selection immediately to avoid double-click requirement
        textarea.addEventListener('mouseup', (e) => {
            lastPointer = { x: e.clientX, y: e.clientY };
            toggleMoveButtonForSelection(textarea, e);
        }, { passive: true });

        textarea.addEventListener('keyup', (e) => {
            debouncedToggle(e);
        }, { passive: true });

        textarea.addEventListener('contextmenu', (e) => {
            const selectionLength = Math.abs(textarea.selectionEnd - textarea.selectionStart);
            if (selectionLength > 0) {
                e.preventDefault();
                lastPointer = { x: e.clientX, y: e.clientY };
                toggleMoveButtonForSelection(textarea, e);
            }
        }, { passive: false });

        textarea.addEventListener('blur', () => hideFloatingMoveButton(), { passive: true });
    });
}

function toggleMoveButtonForSelection(textarea, event) {
    const selectionLength = Math.abs(textarea.selectionEnd - textarea.selectionStart);
    if (selectionLength <= 0) {
        hideFloatingMoveButton();
        return;
    }

    const index = parseInt(textarea.dataset.index, 10);
    if (Number.isNaN(index)) return;

    const beforeText = textarea.value.slice(0, textarea.selectionStart);
    const shouldMovePrev = beforeText.trim().length === 0 && index > 0;

    // Save selection so it doesn't get lost when clicking the floating button
    lastSelection = {
        index,
        start: textarea.selectionStart,
        end: textarea.selectionEnd
    };
    textarea.dataset.selectionStart = String(textarea.selectionStart);
    textarea.dataset.selectionEnd = String(textarea.selectionEnd);

    // Place button near pointer; fallback to textarea position
    let x = lastPointer?.x ?? (event?.clientX ?? 0);
    let y = lastPointer?.y ?? (event?.clientY ?? 0);
    if (!x && !y) {
        const rect = textarea.getBoundingClientRect();
        x = rect.left + 8;
        y = rect.top + 8;
    }

    showFloatingMoveButton(index, x, y, shouldMovePrev ? 'prev' : 'next');
}

function moveSelectionToAdjacentSegment(index, direction, selectionOverride) {
    const current = document.querySelector(`.segment-text[data-index="${index}"]`);
    if (!current) return;

    const targetIndex = direction === 'prev' ? index - 1 : index + 1;
    const target = document.querySelector(`.segment-text[data-index="${targetIndex}"]`);
    if (!target) {
        alert(direction === 'prev' ? 'Không có đoạn trước để chuyển.' : 'Không có đoạn tiếp theo để chuyển.');
        return;
    }

    const start = selectionOverride?.index === index ? selectionOverride.start : (parseInt(current.dataset.selectionStart || current.selectionStart, 10));
    const end = selectionOverride?.index === index ? selectionOverride.end : (parseInt(current.dataset.selectionEnd || current.selectionEnd, 10));
    if (start === end) return;

    const selectedRaw = current.value.slice(start, end);
    const selected = selectedRaw.replace(/\s+/g, ' ').trim();
    if (!selected) {
        hideMoveButton(current);
        return;
    }

    const before = current.value.slice(0, start);
    const after = current.value.slice(end);
    const newCurrent = (before + ' ' + after).replace(/\s+/g, ' ').trim();
    current.value = newCurrent;

    const targetValue = target.value || '';
    const mergedTarget = direction === 'prev'
        ? (targetValue + ' ' + selected).replace(/\s+/g, ' ').trim()
        : (selected + ' ' + targetValue).replace(/\s+/g, ' ').trim();
    target.value = mergedTarget;

    hideFloatingMoveButton();
}

function collectSegments() {
    const segments = [];
    
    // Use currentSegments directly (which is updated when segments are modified/deleted)
    if (currentSegments && currentSegments.length > 0) {
        currentSegments.forEach((base, index) => {
            // Get the textarea value if it exists, otherwise use the stored text
            const textarea = document.querySelector(`.segment-text[data-index="${index}"]`);
            segments.push({
                ...base,
                index: index,
                text: textarea ? textarea.value : (base.text || ''),
                start_time: base.start_time ?? base.start ?? 0,
                end_time: base.end_time ?? 0,
                duration: base.duration ?? 0,
                original_text: base.original_text
                    ?? window.projectData?.segments?.[index]?.original_text
                    ?? window.projectData?.segments?.[index]?.text
                    ?? base.text
                    ?? ''
            });
        });
    } else {
        // Fallback: just collect textarea values
        document.querySelectorAll('.segment-text').forEach((textarea, index) => {
            segments.push({
                index: index,
                text: textarea.value
            });
        });
    }
    
    console.log('Collected segments:', segments);
    return segments;
}

function displayDownloadLinks(files) {
    const downloadLinksSection = document.getElementById('downloadLinks');
    const downloadLinksList = document.getElementById('downloadLinksList');

    if (!downloadLinksList) return;
    
    downloadLinksList.innerHTML = '';

    for (const [fileType, filePath] of Object.entries(files)) {
        const link = document.createElement('a');
        link.href = `/dubsync/projects/${currentProjectId}/download/${fileType}`;
        link.className = 'block text-blue-600 hover:underline';
        link.textContent = `Tải ${fileType.toUpperCase()}`;
        downloadLinksList.appendChild(link);
    }

    if (downloadLinksSection) downloadLinksSection.classList.remove('hidden');
}

async function regenerateSegment(index, text) {
    if (!currentProjectId) return;

    try {
        const response = await fetch(`/dubsync/projects/${currentProjectId}/segments/${index}/regenerate`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({ text })
        });

        const data = await response.json();
        if (data.success) {
            alert('Đã tạo lại giọng nói thành công!');
        } else {
            alert('Lỗi: ' + data.error);
        }
    } catch (error) {
        alert('Lỗi kết nối: ' + error.message);
    }
}

function formatTime(seconds) {
    if (isNaN(seconds)) return '0:00';
    const mins = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return `${mins}:${secs.toString().padStart(2, '0')}`;
}

// View Project - Load from database
function initViewProject() {
    document.querySelectorAll('.view-project-btn').forEach(button => {
        button.addEventListener('click', async function(e) {
            e.preventDefault();
            const projectId = this.dataset.projectId;
            await loadProject(projectId);
        });
    });
}

async function loadProject(projectId) {
    try {
        const response = await fetch(`/dubsync/projects/${projectId}`);
        const data = await response.json();
        
        if (data.success) {
            const project = data.project;
            currentProjectId = project.id;
            
            // Set YouTube URL
            const youtubeUrlInput = document.getElementById('youtubeUrl');
            if (youtubeUrlInput) youtubeUrlInput.value = project.youtube_url;
            
            // Show progress section
            showProgressSection();
            
            // Update steps based on status
            if (project.segments) {
                updateStep('step1', 'completed');
                displaySegments(project.segments);
                showSegmentsEditor();
            }
            
            if (project.translated_segments) {
                updateStep('step2', 'completed');
                displayTranslatedSegments(project.translated_segments);
                document.getElementById('generateTTSBtn')?.classList.remove('hidden');
            }
            
            if (project.audio_segments) {
                updateStep('step3', 'completed');
                document.getElementById('alignTimingBtn')?.classList.remove('hidden');
            }
            
            if (project.aligned_segments) {
                updateStep('step4', 'completed');
                document.getElementById('mergeAudioBtn')?.classList.remove('hidden');
            }
            
            if (project.final_audio_path) {
                updateStep('step5', 'completed');
                showExportSection();
            }
            
            if (project.exported_files) {
                displayDownloadLinks(project.exported_files);
            }
            
            // Scroll to top
            window.scrollTo({ top: 0, behavior: 'smooth' });
            
        } else {
            alert('Lỗi: ' + data.error);
        }
    } catch (error) {
        alert('Lỗi khi tải dự án: ' + error.message);
    }
}

// Tab switching functionality
function initSegmentTabs() {
    const tabButtons = document.querySelectorAll('.segment-tab-btn');
    
    tabButtons.forEach(button => {
        button.addEventListener('click', () => {
            const tabName = button.dataset.tab;
            
            // Remove active state from all buttons
            tabButtons.forEach(btn => {
                btn.classList.remove('active', 'border-red-600');
                btn.classList.add('text-gray-600', 'border-transparent');
            });
            
            // Hide all tab contents
            document.querySelectorAll('.segment-tab-content').forEach(content => {
                content.classList.add('hidden');
            });
            
            // Add active state to clicked button
            button.classList.add('active', 'border-red-600');
            button.classList.remove('text-gray-600', 'border-transparent');
            button.classList.add('text-gray-900');
            
            // Show selected tab content
            const tabContent = document.getElementById(`tab-${tabName}`);
            if (tabContent) {
                tabContent.classList.remove('hidden');
            }
            
            // If switching to full transcript, update the display
            if (tabName === 'full-transcript') {
                updateFullTranscriptDisplay();
            }
        });
    });
}

// Update and display full transcript with timeline
function updateFullTranscriptDisplay() {
    const container = document.getElementById('fullTranscriptContainer');
    if (!container || !currentSegments) return;
    
    // Combine all transcript text without timestamps
    let fullText = currentSegments.map(segment => {
        return segment.text || segment.original_text || '';
    }).join(' ');
    
    // Format: break at sentence boundaries (periods)
    // Split by period and rejoin with proper line breaks
    let formatted = fullText
        .split(/(?<=[.!?])\s+/)  // Split after sentence endings
        .filter(sentence => sentence.trim())  // Remove empty lines
        .join('\n\n');  // Join with double line break
    
    container.innerHTML = '';
    if (formatted.trim()) {
        container.textContent = formatted;
    } else {
        container.innerHTML = '<p class="text-gray-500 text-sm">No transcript available</p>';
    }
}

// Display YouTube metadata (title, description, duration, thumbnail)
function displayYouTubeMetadata(youtubeUrl, metadata) {
    const section = document.getElementById('youtubeInfoSection');
    if (!section) return;
    
    // Update metadata fields
    const titleEl = document.getElementById('videoTitle');
    const descEl = document.getElementById('videoDescription');
    const durationEl = document.getElementById('videoDuration');
    const thumbnailEl = document.getElementById('videoThumbnail');
    const linkEl = document.getElementById('videoLink');
    
    if (metadata.title && titleEl) {
        titleEl.textContent = metadata.title;
    }
    
    if (metadata.description && descEl) {
        descEl.textContent = metadata.description;
    }
    
    if (metadata.duration && durationEl) {
        durationEl.textContent = metadata.duration;
    }
    
    if (metadata.thumbnail && thumbnailEl) {
        thumbnailEl.src = metadata.thumbnail;
    }
    
    if (youtubeUrl && linkEl) {
        linkEl.href = youtubeUrl;
    }
    
    // Show the section
    section.classList.remove('hidden');
}
// Show AI processing status indicator
function showAIProcessingStatus() {
    const section = document.getElementById('aiProcessingStatus');
    if (section) {
        section.classList.remove('hidden');
        console.log('aiProcessingStatus: shown', {
            hidden: section.classList.contains('hidden'),
            display: window.getComputedStyle(section).display
        });
    } else {
        console.warn('aiProcessingStatus: element not found');
    }
}

// Hide AI processing status indicator
function hideAIProcessingStatus() {
    const section = document.getElementById('aiProcessingStatus');
    if (section) {
        section.classList.add('hidden');
        console.log('aiProcessingStatus: hidden', {
            hidden: section.classList.contains('hidden'),
            display: window.getComputedStyle(section).display
        });
    } else {
        console.warn('aiProcessingStatus: element not found');
    }
}

// Poll AI segmentation progress
let pollInterval = null;
function pollAIProgress(projectId) {
    console.log('Starting to poll AI progress for project:', projectId);
    
    // Clear any existing poll interval
    if (pollInterval) {
        clearInterval(pollInterval);
    }
    
    // Poll every 2 seconds
    pollInterval = setInterval(async () => {
        try {
            const response = await fetch('/dubsync/check-ai-progress', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({
                    project_id: projectId
                })
            });

            if (!response.ok) {
                const errorText = await response.text();
                console.error('Error checking progress:', response.status, errorText);
                return;
            }

            let data;
            try {
                const contentType = response.headers.get('content-type') || '';
                if (!contentType.includes('application/json')) {
                    const nonJsonText = await response.text();
                    console.error('Error polling progress: non-JSON response', {
                        status: response.status,
                        contentType,
                        body: nonJsonText
                    });
                    return;
                }
                data = await response.json();
            } catch (parseError) {
                const fallbackText = await response.text();
                console.error('Error polling progress: invalid JSON response', fallbackText);
                return;
            }
            console.log('AI Progress:', data);

            // Update UI with progress
            updateAIProgressUI(data);

            // If complete, fetch segments and stop polling
            if (data.is_complete) {
                console.log('AI processing complete!');
                clearInterval(pollInterval);
                pollInterval = null;
                
                // Small delay then hide loading and show segments
                setTimeout(() => {
                    hideAIProcessingStatus();
                    loadProjectSegments(projectId);
                }, 500);
            }
        } catch (error) {
            console.error('Error polling progress:', error);
        }
    }, 2000); // Poll every 2 seconds
}

// Update AI progress UI
function updateAIProgressUI(data) {
    const messageEl = document.getElementById('aiStatusMessage');
    const progressBar = document.getElementById('aiProgressBar');
    const progressPercent = document.getElementById('aiProgressPercent');

    console.log('aiProcessingStatus: update', {
        status: data.status,
        percentage: data.percentage,
        message: data.message
    });
    
    if (messageEl) {
        messageEl.textContent = data.message || 'Đang xử lý...';
    }
    
    if (progressBar) {
        progressBar.style.width = data.percentage + '%';
    }
    
    if (progressPercent) {
        progressPercent.textContent = data.percentage;
    }
}

// Load and display project segments
async function loadProjectSegments(projectId) {
    try {
        const response = await fetch(`/dubsync/projects/${projectId}`, {
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            }
        });

        if (!response.ok) {
            console.error('Error loading project:', response.status);
            alert('Lỗi khi tải segments');
            return;
        }

        const data = await response.json();
        console.log('Project data loaded:', data);

        if (data.segments) {
            displaySegments(data.segments);
            showSegmentsEditor();
        }
    } catch (error) {
        console.error('Error loading project segments:', error);
        alert('Lỗi khi tải segments: ' + error.message);
    }
}

// YouTube channel reference fetch
function initChannelReference() {
    const fetchBtn = document.getElementById('fetchChannelVideosBtn');
    const channelUrlInput = document.getElementById('youtubeChannelUrl');
    const statusEl = document.getElementById('channelFetchStatus');
    const channelInfo = document.getElementById('channelInfo');
    const channelTitle = document.getElementById('channelTitle');
    const channelIdText = document.getElementById('channelIdText');
    const channelThumbnail = document.getElementById('channelThumbnail');
    const videosList = document.getElementById('channelVideosList');
    const videosContainer = document.getElementById('channelVideosContainer');

    if (!fetchBtn || !channelUrlInput) return;

    fetchBtn.addEventListener('click', async (e) => {
        e.preventDefault();
        const channelUrl = channelUrlInput.value?.trim();
        if (!channelUrl) {
            alert('Vui lòng nhập URL kênh YouTube');
            return;
        }

        const endpoint = fetchBtn.dataset.endpoint;
        if (!endpoint) {
            alert('Thiếu endpoint để lấy dữ liệu kênh');
            return;
        }

        fetchBtn.disabled = true;
        fetchBtn.textContent = 'Đang lấy dữ liệu...';

        if (statusEl) {
            statusEl.classList.remove('hidden');
            statusEl.textContent = 'Đang gọi YouTube API...';
        }

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({ channel_url: channelUrl })
            });

            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.error || 'Không thể lấy dữ liệu kênh');
            }

            if (channelInfo) {
                channelInfo.classList.remove('hidden');
                if (channelTitle) channelTitle.textContent = data.channel?.title || '';
                if (channelIdText) channelIdText.textContent = data.channel?.id ? `Channel ID: ${data.channel.id}` : '';
                if (channelThumbnail && data.channel?.thumbnail) channelThumbnail.src = data.channel.thumbnail;
            }

            if (videosContainer) {
                videosContainer.innerHTML = '';
                (data.videos || []).forEach((video) => {
                    const item = document.createElement('div');
                    item.className = 'flex items-center gap-3 p-3 border border-gray-200 rounded-lg';
                    item.innerHTML = `
                        <img src="${video.thumbnail || ''}" alt="Thumbnail" class="w-16 h-10 rounded object-cover border" />
                        <div class="min-w-0 flex-1">
                            <div class="text-sm font-semibold text-gray-900 truncate">${video.title || ''}</div>
                            <div class="text-xs text-gray-500">${video.video_id || ''}</div>
                        </div>
                        <a href="${video.video_url || '#'}" target="_blank" class="text-xs text-red-600 hover:text-red-700">Open</a>
                    `;
                    videosContainer.appendChild(item);
                });
            }

            if (videosList) {
                videosList.classList.remove('hidden');
            }

            if (statusEl) {
                statusEl.textContent = `Đã tải ${data.videos?.length || 0} video.`;
            }
        } catch (error) {
            if (statusEl) {
                statusEl.textContent = error.message || 'Có lỗi xảy ra';
            }
        } finally {
            fetchBtn.disabled = false;
            fetchBtn.textContent = 'Fetch Channel Videos';
        }
    });
}