(function () {
    const settings = window.realMediaExportSettings || {};
    const form = document.getElementById('real-media-export-form');
    const logContainer = document.getElementById('real-media-export-log');
    const resultsContainer = document.getElementById('real-media-export-results');
    const messageContainer = document.getElementById('real-media-export-message');
    let isProcessing = false;

    const getString = (key, fallback) => {
        if (settings && settings.i18n && Object.prototype.hasOwnProperty.call(settings.i18n, key)) {
            return settings.i18n[key];
        }
        return fallback;
    };

    const formatTemplate = (template, values) => {
        if (typeof template !== 'string') {
            return '';
        }
        const positional = Array.isArray(values) ? values : [];
        const queue = positional.slice();
        return template.replace(/%(\d+)\$s|%s/g, (match, index) => {
            if (index) {
                const i = parseInt(index, 10) - 1;
                return typeof positional[i] !== 'undefined' ? positional[i] : '';
            }
            return queue.length ? queue.shift() : '';
        });
    };

    const formatNumber = (value) => {
        try {
            return new Intl.NumberFormat(window.navigator.language || undefined).format(value);
        } catch (err) {
            return String(value);
        }
    };

    const statusToNoticeClass = (status) => {
        if ('success' === status) {
            return 'notice notice-success';
        }
        if ('warning' === status) {
            return 'notice notice-warning';
        }
        if ('error' === status) {
            return 'notice notice-error';
        }
        return 'notice';
    };

    const ensureLogList = () => {
        if (!logContainer) {
            return null;
        }
        let list = logContainer.querySelector('.real-media-export-log__list');
        if (!list) {
            logContainer.innerHTML = '';
            list = document.createElement('ul');
            list.className = 'real-media-export-log__list';
            logContainer.appendChild(list);
        }
        return list;
    };

    const clearLog = () => {
        if (!logContainer) {
            return;
        }
        logContainer.innerHTML = '';
    };

    const appendLog = (message, type = 'info') => {
        if (!message || !logContainer) {
            return;
        }
        const list = ensureLogList();
        if (!list) {
            return;
        }
        const item = document.createElement('li');
        item.className = 'real-media-export-log__item is-' + type;

        const time = document.createElement('span');
        time.className = 'real-media-export-log__time';
        time.textContent = new Date().toLocaleTimeString();

        const text = document.createElement('span');
        text.className = 'real-media-export-log__message';
        text.textContent = message;

        item.appendChild(time);
        item.appendChild(text);
        list.appendChild(item);
        logContainer.scrollTo({ top: logContainer.scrollHeight, behavior: 'smooth' });
    };

    const setMessage = (payload) => {
        if (!messageContainer) {
            return;
        }
        const status = payload && payload.status ? payload.status : '';
        messageContainer.setAttribute('data-status', status);
        messageContainer.innerHTML = '';
        const messageHtml = payload && payload.message_html ? payload.message_html : '';
        const messageText = payload && payload.message_text ? payload.message_text : '';
        const content = messageHtml || messageText;
        if (!content) {
            return;
        }
        const notice = document.createElement('div');
        notice.className = statusToNoticeClass(status);
        const inner = document.createElement('div');
        inner.className = 'real-media-export-message__content';
        if (messageHtml) {
            inner.innerHTML = messageHtml;
        } else {
            inner.textContent = messageText;
        }
        notice.appendChild(inner);
        messageContainer.appendChild(notice);
    };

    const setResultsLoading = () => {
        if (!resultsContainer) {
            return;
        }
        resultsContainer.innerHTML = '';
        const loading = document.createElement('div');
        loading.className = 'real-media-export-loading';
        loading.textContent = getString('loading', 'Création des archives…');
        resultsContainer.appendChild(loading);
    };

    const renderResults = (payload) => {
        if (!resultsContainer) {
            return;
        }
        resultsContainer.innerHTML = '';
        const summary = payload && payload.summary ? payload.summary : null;
        const archives = Array.isArray(payload && payload.archives) ? payload.archives : [];
        const headerParts = [];

        if (payload && payload.generated_at_formatted) {
            const template = getString('generatedOn', 'Généré le %s');
            const timestamp = document.createElement('span');
            timestamp.className = 'real-media-export-results__timestamp';
            timestamp.textContent = formatTemplate(template, [payload.generated_at_formatted]);
            headerParts.push(timestamp);
        }

        if (summary) {
            const parts = [];
            if (summary.files_exported || summary.files_total) {
                const template = getString('filesExportedSummary', '%1$s fichier(s) exporté(s) sur %2$s');
                parts.push(
                    formatTemplate(template, [
                        formatNumber(summary.files_exported || 0),
                        formatNumber(summary.files_total || 0),
                    ])
                );
            }
            const archiveCount = typeof summary.archives_count !== 'undefined' ? summary.archives_count : archives.length;
            if (archiveCount) {
                const template = getString('archivesCountSummary', '%s archive(s)');
                parts.push(formatTemplate(template, [formatNumber(archiveCount)]));
            }
            if (summary.files_skipped) {
                const template = getString('filesSkippedSummary', '%s fichier(s) ignoré(s)');
                parts.push(formatTemplate(template, [formatNumber(summary.files_skipped)]));
            }
            if (parts.length) {
                const info = document.createElement('span');
                info.className = 'real-media-export-results__summary';
                info.textContent = parts.join(' · ');
                headerParts.push(info);
            }
        }

        if (headerParts.length) {
            const header = document.createElement('div');
            header.className = 'real-media-export-results__header';
            headerParts.forEach((part) => header.appendChild(part));
            resultsContainer.appendChild(header);
        }

        if (!archives.length) {
            const placeholder = document.createElement('p');
            placeholder.className = 'real-media-export-results__placeholder';
            placeholder.textContent = getString('resultsPlaceholder', 'Les liens de téléchargement apparaîtront ici une fois les archives prêtes.');
            resultsContainer.appendChild(placeholder);
            return;
        }

        const grid = document.createElement('div');
        grid.className = 'real-media-export-results__grid';

        const renderFolderTree = (nodes) => {
            if (!Array.isArray(nodes) || !nodes.length) return null;
            const ul = document.createElement('ul');
            ul.className = 'real-media-export-card__tree';
            nodes.forEach((n) => {
                if (!n) return;
                const li = document.createElement('li');
                const name = typeof n.name === 'string' ? n.name : '';
                const count = typeof n.count === 'number' ? n.count : 0;
                li.textContent = name ? `${name} (${formatNumber(count)})` : String(formatNumber(count));
                const child = renderFolderTree(n.children);
                if (child) li.appendChild(child);
                ul.appendChild(li);
            });
            return ul;
        };

        archives.forEach((archive, index) => {
            const card = document.createElement('article');
            card.className = 'real-media-export-card';
            card.style.setProperty('--real-media-export-card-index', String(index));

            const header = document.createElement('header');
            header.className = 'real-media-export-card__header';
            const title = document.createElement('h3');
            title.className = 'real-media-export-card__title';
            title.textContent = archive && archive.file ? archive.file : getString('archiveTitleFallback', 'Archive ZIP');
            header.appendChild(title);
            if (archive && archive.created_at_formatted) {
                const subtitle = document.createElement('p');
                subtitle.className = 'real-media-export-card__subtitle';
                subtitle.textContent = formatTemplate(getString('generatedOn', 'Généré le %s'), [archive.created_at_formatted]);
                header.appendChild(subtitle);
            }
            card.appendChild(header);

            const metaList = document.createElement('ul');
            metaList.className = 'real-media-export-card__meta';
            const metaEntries = [
                { label: getString('compressedSizeLabel', 'Taille compressée'), value: archive && archive.size_human },
                { label: getString('originalsSizeLabel', 'Taille cumulée des originaux'), value: archive && archive.bytes_total_human },
                { label: getString('filesCountLabel', 'Nombre de fichiers'), value: archive && archive.file_count ? formatNumber(archive.file_count) : '' },
            ];
            metaEntries.forEach((entry) => {
                if (!entry.value) {
                    return;
                }
                const item = document.createElement('li');
                const label = document.createElement('span');
                label.className = 'real-media-export-card__meta-label';
                label.textContent = entry.label;
                const value = document.createElement('span');
                value.className = 'real-media-export-card__meta-value';
                value.textContent = entry.value;
                item.appendChild(label);
                item.appendChild(value);
                metaList.appendChild(item);
            });
            card.appendChild(metaList);

            const details = [
                { label: getString('primaryFoldersLabel', 'Dossiers principaux'), value: archive && Array.isArray(archive.folders) ? archive.folders.join(', ') : '' },
                { label: getString('previewFilesLabel', 'Exemples de fichiers'), value: archive && Array.isArray(archive.files_preview) ? archive.files_preview.join(', ') : '' },
            ];
            details.forEach((detail) => {
                if (!detail.value) {
                    return;
                }
                const paragraph = document.createElement('p');
                paragraph.className = 'real-media-export-card__detail';
                const detailLabel = document.createElement('span');
                detailLabel.className = 'real-media-export-card__detail-label';
                detailLabel.textContent = detail.label;
                const detailValue = document.createElement('span');
                detailValue.className = 'real-media-export-card__detail-value';
                detailValue.textContent = detail.value;
                paragraph.appendChild(detailLabel);
                paragraph.appendChild(detailValue);
                card.appendChild(paragraph);
            });

            if (Array.isArray(archive && archive.folder_tree) && archive.folder_tree.length) {
                const block = document.createElement('div');
                block.className = 'real-media-export-card__detail';
                const label = document.createElement('span');
                label.className = 'real-media-export-card__detail-label';
                label.textContent = getString('foldersTreeLabel', 'Structure des dossiers');
                block.appendChild(label);
                const tree = renderFolderTree(archive.folder_tree);
                if (tree) block.appendChild(tree);
                card.appendChild(block);
            }

            if (archive && archive.max_size_reached) {
                const note = document.createElement('p');
                note.className = 'real-media-export-card__note';
                note.textContent = getString('maxSizeReachedNote', 'Cet archive a été clôturée automatiquement car la taille maximale définie a été atteinte.');
                card.appendChild(note);
            }

            const actions = document.createElement('div');
            actions.className = 'real-media-export-card__actions';
            if (archive && archive.download_url) {
                const button = document.createElement('a');
                button.className = 'button button-primary';
                button.href = archive.download_url;
                button.textContent = getString('downloadLabel', 'Télécharger');
                actions.appendChild(button);
            } else {
                const unavailable = document.createElement('p');
                unavailable.className = 'real-media-export-card__note';
                unavailable.textContent = getString('downloadUnavailable', 'Lien de téléchargement indisponible.');
                card.appendChild(unavailable);
            }
            const del = document.createElement('button');
            del.type = 'button';
            del.className = 'button-link-delete real-media-export-card__delete';
            del.dataset.file = archive && archive.file ? archive.file : '';
            del.textContent = getString('deleteLabel', 'Supprimer');
            actions.appendChild(del);
            card.appendChild(actions);

            grid.appendChild(card);
        });

        resultsContainer.appendChild(grid);
    };

    const toggleProcessing = (active) => {
        isProcessing = active;
        if (!form) {
            return;
        }
        const submit = form.querySelector('button[type="submit"], input[type="submit"]');
        if (submit) {
            submit.disabled = active;
            submit.classList.toggle('is-busy', !!active);
        }
        form.classList.toggle('is-processing', active);
    };

    const handleResultPayload = (payload) => {
        if (!payload) {
            return;
        }
        setMessage(payload);
        renderResults(payload);
        if (Array.isArray(payload.activity) && payload.activity.length) {
            payload.activity.forEach((entry) => {
                if (!entry) return;
                const msg = typeof entry.message === 'string' ? entry.message : '';
                const type = entry.type === 'warning' || entry.type === 'error' ? entry.type : 'info';
                if (msg) appendLog(msg, type);
            });
        }
        if (Array.isArray(payload.files_skipped) && payload.files_skipped.length) {
            payload.files_skipped.forEach((warning) => appendLog(warning, 'warning'));
        }
        if (payload.message_text) {
            const type = payload.status === 'warning' ? 'warning' : payload.status === 'error' ? 'error' : 'success';
            appendLog(payload.message_text, type);
        }
        const readyStatus = payload.status === 'warning' ? 'warning' : payload.status === 'error' ? 'error' : 'success';
        appendLog(getString('ready', 'Liens de téléchargement disponibles.'), readyStatus);
    };

    if (form && settings.ajaxUrl) {
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            if (isProcessing) {
                return;
            }
            toggleProcessing(true);
            clearLog();
            appendLog(getString('preparing', 'Préparation de la génération…'));
            appendLog(getString('processing', 'Analyse des fichiers et création des archives…'));
            setMessage({ status: '', message_html: '' });
            setResultsLoading();

            const formData = new FormData(form);
            formData.set('action', 'real_media_export_generate');

            fetch(settings.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData,
            })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error('network');
                    }
                    return response.json();
                })
                .then((data) => {
                    if (!data) {
                        throw new Error('empty');
                    }
                    if (!data.success) {
                        const payload = data.data || {};
                        setMessage(payload);
                        renderResults(payload);
                        appendLog(payload.message_text || getString('error', 'Une erreur est survenue pendant la génération.'), 'error');
                        throw new Error('server');
                    }
                    handleResultPayload(data.data || {});
                })
                .catch((error) => {
                    if (error && error.message === 'server') {
                        return;
                    }
                    renderResults({ archives: [] });
                    const errorMessage = getString('error', 'Une erreur est survenue pendant la génération.');
                    setMessage({ status: 'error', message_text: errorMessage });
                    appendLog(errorMessage, 'error');
                })
                .finally(() => {
                    toggleProcessing(false);
                });
        });
    }

    if (settings.initialResult) {
        handleResultPayload(settings.initialResult);
    }

    // Deletion handling (event delegation on results container)
    if (resultsContainer && settings.ajaxUrl && settings.deleteNonce) {
        resultsContainer.addEventListener('click', (ev) => {
            const btn = ev.target && ev.target.closest ? ev.target.closest('.real-media-export-card__delete') : null;
            if (!btn) return;
            ev.preventDefault();
            const file = btn.dataset.file || '';
            if (!file) return;
            if (!confirm(getString('confirmDelete', 'Supprimer ce fichier ZIP du disque ?'))) {
                return;
            }
            btn.disabled = true;
            const formData = new FormData();
            formData.set('action', 'real_media_export_delete');
            formData.set('file', file);
            formData.set('_wpnonce', settings.deleteNonce);

            fetch(settings.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData,
            })
                .then((response) => response.json())
                .then((data) => {
                    if (!data || !data.success) {
                        throw new Error('fail');
                    }
                    // Remove card
                    const card = btn.closest('.real-media-export-card');
                    if (card && card.parentNode) {
                        card.parentNode.removeChild(card);
                    }
                    // If grid is empty, show placeholder
                    const grid = resultsContainer.querySelector('.real-media-export-results__grid');
                    if (grid && !grid.children.length) {
                        resultsContainer.innerHTML = '';
                        const placeholder = document.createElement('p');
                        placeholder.className = 'real-media-export-results__placeholder';
                        placeholder.textContent = getString('resultsPlaceholder', 'Les liens de téléchargement apparaîtront ici une fois les archives prêtes.');
                        resultsContainer.appendChild(placeholder);
                    }
                    appendLog(getString('deleted', 'Archive supprimée.'), 'info');
                })
                .catch(() => {
                    appendLog(getString('deleteError', 'Impossible de supprimer l’archive.'), 'error');
                    btn.disabled = false;
                });
        });
    }
})();
