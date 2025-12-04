(function ($) {
    $(function () {
        const tabs = $("#kasumi-ai-tabs");
        const adminData = window.kasumiAiAdmin || {};
        const wpApi = window.wp || {};

        if (tabs.length) {
            tabs.tabs({
                history: false,
                activate: function (event, ui) {
                    // Usuń hash z URL jeśli został dodany
                    if (window.location.hash) {
                        window.history.replaceState(
                            null,
                            "",
                            window.location.pathname + window.location.search,
                        );
                    }
                },
            });
        }

        $("[data-kasumi-tooltip]").tooltip({
            items: "[data-kasumi-tooltip]",
            content: function () {
                return $(this).attr("data-kasumi-tooltip");
            },
            position: {
                my: "left center",
                at: "right+15 center",
                collision: "flipfit",
            },
            tooltipClass: "kasumi-tooltip",
        });

        // Pokazywanie/ukrywanie pól w zależności od wyboru dostawcy AI
        const toggleProviderFields = function () {
            const providerSelect = $(
                'select[name="kasumi_ai_options[ai_provider]"]',
            );
            if (!providerSelect.length) {
                return;
            }

            const provider = providerSelect.val() || "openai";
            const openaiFields = $(".kasumi-openai-fields");
            const geminiFields = $(".kasumi-gemini-fields");

            // Ukryj wszystkie pola
            openaiFields.removeClass("show").hide();
            geminiFields.removeClass("show").hide();

            // Pokaż odpowiednie pola
            if (provider === "openai") {
                openaiFields.addClass("show").show();
            } else if (provider === "gemini") {
                geminiFields.addClass("show").show();
            } else if (provider === "auto") {
                openaiFields.addClass("show").show();
                geminiFields.addClass("show").show();
            }
        };

        const toggleAuthorModeControls = function () {
            const select = $(
                'select[name="kasumi_ai_options[default_author_mode]"]',
            );

            if (!select.length) {
                return;
            }

            const mode = select.val() || "none";
            const fixedControls = $(".kasumi-author-control--fixed");
            const randomControls = $(".kasumi-author-control--random");

            if (mode === "fixed") {
                fixedControls.show();
            } else {
                fixedControls.hide();
            }

            if (mode === "random_list") {
                randomControls.show();
            } else {
                randomControls.hide();
            }
        };

        // Inicjalizacja przy załadowaniu
        toggleProviderFields();
        toggleAuthorModeControls();
        initCanvasPresetControl();

        // Zmiana dostawcy
        $(document).on(
            "change",
            'select[name="kasumi_ai_options[ai_provider]"]',
            toggleProviderFields,
        );
        $(document).on(
            "change",
            'select[name="kasumi_ai_options[default_author_mode]"]',
            toggleAuthorModeControls,
        );
        $(document).on(
            "change",
            'select[name="kasumi_ai_options[ai_provider]"]',
            toggleProviderFields,
        );

        const fetchModels = function (control, autoload) {
            const select = control.find("[data-kasumi-model]");
            const provider = select.data("kasumi-model");
            if (!provider || !adminData.ajaxUrl) {
                return;
            }

            const spinner = control.find(".kasumi-model-spinner");
            if (autoload) {
                spinner.addClass("is-active");
            }

            const formData = new window.FormData();
            formData.append("action", "kasumi_ai_models");
            formData.append("nonce", adminData.nonce || "");
            formData.append("provider", provider);

            if (!wpApi.apiFetch) {
                return;
            }

            wpApi
                .apiFetch({
                    url: adminData.ajaxUrl,
                    method: "POST",
                    body: formData,
                })
                .then(function (payload) {
                    if (!payload.success) {
                        var genericError =
                            adminData.i18n && adminData.i18n.error
                                ? adminData.i18n.error
                                : "Error";
                        var message =
                            payload.data && payload.data.message
                                ? payload.data.message
                                : genericError;
                        throw new Error(message);
                    }

                    const models = payload.data.models || [];
                    const current =
                        select.data("current-value") || select.val();
                    select.empty();

                    if (!models.length) {
                        const emptyLabel =
                            adminData.i18n && adminData.i18n.noModels
                                ? adminData.i18n.noModels
                                : "No models";
                        select.append($("<option>").text(emptyLabel));
                    } else {
                        models.forEach(function (model) {
                            select.append(
                                $("<option>")
                                    .val(model.id)
                                    .text(model.label || model.id),
                            );
                        });
                    }

                    if (current) {
                        select.val(current);
                    }
                })
                .catch(function (error) {
                    const message =
                        error.message ||
                        (adminData.i18n && adminData.i18n.error) ||
                        "Error";
                    window.alert(message);
                })
                .finally(function () {
                    spinner.removeClass("is-active");
                });
        };

        $(".kasumi-model-control").each(function () {
            const control = $(this);
            const refresh = control.find(".kasumi-refresh-models");

            refresh.on("click", function () {
                fetchModels(control, true);
            });

            if ("1" === control.data("autoload")) {
                fetchModels(control, false);
            }
        });

        // Inicjalizacja WordPress Color Picker
        if ($.fn.wpColorPicker) {
            $(".wp-color-picker-field").wpColorPicker();
        }

        if (adminData.scheduler) {
            initScheduler(adminData.scheduler);
        }

        initPrimaryLinksRepeater();
    });

    function initScheduler(config) {
        const root = document.getElementById("kasumi-schedule-manager");
        const apiFetch =
            window.wp && window.wp.apiFetch ? window.wp.apiFetch : null;
        const nativeFetch = window.fetch ? window.fetch.bind(window) : null;
        const ajaxConfig = config.ajax || {};

        if (
            !root ||
            (!apiFetch && !nativeFetch && !ajaxConfig.url)
        ) {
            return;
        }

        const elements = {
            form: root.querySelector("[data-kasumi-schedule-form]"),
            alert: root.querySelector("[data-kasumi-schedule-alert]"),
            table: root.querySelector("[data-kasumi-schedule-table]"),
            statusFilter: root.querySelector('[data-kasumi-filter="status"]'),
            authorFilter: root.querySelector('[data-kasumi-filter="author"]'),
            searchFilter: root.querySelector('[data-kasumi-filter="search"]'),
            refreshBtn: root.querySelector("[data-kasumi-refresh]"),
            resetBtn: root.querySelector("[data-kasumi-reset-form]"),
            postTypeSelect: root.querySelector("#kasumi-schedule-post-type"),
            authorSelect: root.querySelector("#kasumi-schedule-author"),
            submitBtn: root.querySelector("[data-kasumi-schedule-submit]"),
            modelSelect: root.querySelector("#kasumi-schedule-model"),
        };

        const state = {
            items: [],
            loading: false,
            editId: null,
            filters: {
                status: "",
                author: "",
                search: "",
                page: 1,
                per_page: 20,
            },
        };

        populateSelect(elements.postTypeSelect, config.postTypes);
        populateSelect(elements.authorSelect, config.authors, true);
        populateSelect(elements.authorFilter, config.authors, true);

        populateSelect(elements.modelSelect, config.models, true);

        if (elements.submitBtn) {
            elements.submitBtn.addEventListener("click", function (event) {
                event.preventDefault();
                saveSchedule();
            });
        }

        if (elements.resetBtn) {
            elements.resetBtn.addEventListener("click", function () {
                resetForm();
            });
        }

        if (elements.statusFilter) {
            elements.statusFilter.addEventListener("change", function (event) {
                state.filters.status = event.target.value;
                state.filters.page = 1;
                fetchSchedules();
            });
        }

        if (elements.authorFilter) {
            elements.authorFilter.addEventListener("change", function (event) {
                state.filters.author = event.target.value;
                state.filters.page = 1;
                fetchSchedules();
            });
        }

        if (elements.searchFilter) {
            elements.searchFilter.addEventListener(
                "input",
                debounce(function (event) {
                    state.filters.search = event.target.value;
                    state.filters.page = 1;
                    fetchSchedules();
                }, 400),
            );
        }

        if (elements.refreshBtn) {
            elements.refreshBtn.addEventListener("click", function () {
                fetchSchedules();
            });
        }

        if (elements.table) {
            elements.table.addEventListener("click", function (event) {
                const action = event.target.getAttribute("data-action");
                const id = parseInt(event.target.getAttribute("data-id"), 10);

                if (!action || !id) {
                    return;
                }

                if ("edit" === action) {
                    const item = state.items.find(function (entry) {
                        return entry.id === id;
                    });
                    if (item) {
                        fillForm(item);
                    }
                }

                if ("delete" === action) {
                    if (window.confirm(config.i18n.deleteConfirm)) {
                        deleteSchedule(id);
                    }
                }

                if ("run" === action) {
                    runSchedule(id);
                }
            });
        }

        resetForm();
        fetchSchedules();

        function populateSelect(select, options, includePlaceholder) {
            if (!select || !options) {
                return;
            }

            select.innerHTML = "";

            if (includePlaceholder) {
                const option = document.createElement("option");
                option.value = "";
                option.textContent = select.dataset.placeholder || "—";
                select.appendChild(option);
            }

            options.forEach(function (option) {
                const node = document.createElement("option");
                node.value = option.value || option.id;
                node.textContent = option.label || option.name;
                select.appendChild(node);
            });
        }

        function setNotice(type, message) {
            if (!elements.alert) {
                return;
            }

            if (!message) {
                elements.alert.style.display = "none";
                elements.alert.textContent = "";
                return;
            }

            elements.alert.className = "notice notice-" + type;
            elements.alert.textContent = message;
            elements.alert.style.display = "block";
        }

        function getField(name) {
            return elements.form
                ? elements.form.querySelector('[name="' + name + '"]')
                : null;
        }

        function getFieldValue(name, fallback) {
            const field = getField(name);
            if (!field) {
                return fallback || "";
            }

            if (field.type === "checkbox") {
                return field.checked ? field.value || "1" : "";
            }

            return field.value ? field.value.toString() : fallback || "";
        }

        function setFieldValue(name, value) {
            const field = getField(name);
            if (!field) {
                return;
            }

            if (field.type === "checkbox") {
                field.checked = Boolean(value);
            } else {
                field.value = value ?? "";
            }
        }

        function serializeForm() {
            if (!elements.form) {
                return {};
            }

            return {
                postTitle: getFieldValue("postTitle", "").trim(),
                status: getFieldValue("status", "draft"),
                postType: getFieldValue("postType", "post"),
                postStatus: getFieldValue("postStatus", "draft"),
                authorId: getFieldValue("authorId", ""),
                publishAt: getFieldValue("publishAt", ""),
                model: getFieldValue("model", ""),
                systemPrompt: getFieldValue("systemPrompt", ""),
                userPrompt: getFieldValue("userPrompt", ""),
            };
        }

        function saveSchedule() {
            const payload = serializeForm();
            const validationError = validateSchedulePayload(payload);

            if (validationError) {
                setNotice("error", validationError);
                return;
            }

            setNotice("info", config.i18n.loading);
            if (elements.submitBtn) {
                elements.submitBtn.setAttribute("disabled", "disabled");
            }

            const method = state.editId ? "PATCH" : "POST";
            const url = state.editId
                ? config.restUrl + "/" + state.editId
                : config.restUrl;

            apiFetch({
                url: url,
                method: method,
                headers: {
                    "X-WP-Nonce": config.nonce,
                    "Content-Type": "application/json",
                },
                body: JSON.stringify(payload),
            })
                .then(function () {
                    const successMessage = state.editId
                        ? config.i18n.updated || config.i18n.updateLabel || ""
                        : config.i18n.saved ||
                          config.i18n.save ||
                          config.i18n.saveLabel ||
                          "";
                    setNotice("success", successMessage);
                    resetForm();
                    fetchSchedules();
                })
                .catch(function () {
                    setNotice("error", config.i18n.error);
                })
                .finally(function () {
                    if (elements.submitBtn) {
                        elements.submitBtn.removeAttribute("disabled");
                    }
                });
        }

        function fetchSchedules() {
            state.loading = true;
            renderTable();

            const params = new window.URLSearchParams();
            Object.entries(state.filters).forEach(function (entry) {
                const key = entry[0];
                const value = entry[1];
                if (value) {
                    params.append(key, value);
                }
            });

            apiFetch({
                url: config.restUrl + "?" + params.toString(),
                method: "GET",
                headers: {
                    "X-WP-Nonce": config.nonce,
                },
            })
                .then(function (response) {
                    state.items = response.items || [];
                    state.loading = false;
                    renderTable();
                })
                .catch(function () {
                    state.items = [];
                    state.loading = false;
                    setNotice("error", config.i18n.error);
                    renderTable();
                });
        }

        function renderTable() {
            if (!elements.table) {
                return;
            }

            if (state.loading) {
                elements.table.innerHTML = "<p>" + config.i18n.loading + "</p>";
                return;
            }

            if (!state.items.length) {
                elements.table.innerHTML = "<p>" + config.i18n.empty + "</p>";
                return;
            }

            const rows = state.items.map(function (item) {
                return [
                    "<tr>",
                    "<td><strong>" +
                        escapeHtml(item.postTitle || "(Untitled)") +
                        "</strong><br><small>" +
                        escapeHtml(item.userPrompt || "").slice(0, 120) +
                        "</small></td>",
                    "<td>" + formatStatus(item.status) + "</td>",
                    "<td>" +
                        (item.publishAt
                            ? new Date(item.publishAt).toLocaleString()
                            : config.i18n.noDate) +
                        "</td>",
                    "<td>" + (item.authorId || "—") + "</td>",
                    '<td class="kasumi-table-actions">' +
                        '<button type="button" class="button button-link" data-action="edit" data-id="' +
                        item.id +
                        '">' +
                        config.i18n.edit +
                        "</button>" +
                        '<button type="button" class="button button-link" data-action="run" data-id="' +
                        item.id +
                        '">' +
                        config.i18n.runAction +
                        "</button>" +
                        '<button type="button" class="button button-link button-link-delete" data-action="delete" data-id="' +
                        item.id +
                        '">' +
                        config.i18n.delete +
                        "</button>" +
                        "</td>",
                    "</tr>",
                ].join("");
            });

            elements.table.innerHTML =
                '<table class="widefat striped"><thead><tr><th>' +
                config.i18n.taskLabel +
                "</th><th>" +
                config.i18n.statusLabel +
                "</th><th>" +
                config.i18n.publishLabel +
                "</th><th>ID</th><th></th></tr></thead><tbody>" +
                rows.join("") +
                "</tbody></table>";
        }

        function fillForm(item) {
            if (!elements.form) {
                return;
            }

            state.editId = item.id;

            elements.form.querySelector('[name="postTitle"]').value =
                item.postTitle || "";
            elements.form.querySelector('[name="status"]').value =
                item.status || "draft";
            elements.form.querySelector('[name="postType"]').value =
                item.postType || "post";
            elements.form.querySelector('[name="postStatus"]').value =
                item.postStatus || "draft";
            elements.form.querySelector('[name="authorId"]').value =
                item.authorId || "";
            if (elements.modelSelect) {
                elements.modelSelect.value = item.model || "";
            }
            elements.form.querySelector('[name="systemPrompt"]').value =
                item.systemPrompt || "";
            elements.form.querySelector('[name="userPrompt"]').value =
                item.userPrompt || "";
            elements.form.querySelector('[name="publishAt"]').value =
                toDateInputValue(item.publishAt);

            if (elements.submitBtn) {
                elements.submitBtn.textContent =
                    config.i18n.updateLabel || config.i18n.updated || "";
            }
        }

        function resetForm() {
            state.editId = null;

            const defaultPostType =
                Array.isArray(config.postTypes) && config.postTypes.length
                    ? config.postTypes[0].value
                    : "post";

            setFieldValue("postTitle", "");
            setFieldValue("status", "draft");
            setFieldValue("postType", defaultPostType);
            setFieldValue("postStatus", "draft");
            setFieldValue("authorId", "");
            setFieldValue("publishAt", "");
            setFieldValue("model", "");
            setFieldValue("systemPrompt", "");
            setFieldValue("userPrompt", "");

            if (elements.postTypeSelect) {
                elements.postTypeSelect.value = defaultPostType;
            }

            if (elements.modelSelect) {
                elements.modelSelect.value = "";
            }

            if (elements.submitBtn) {
                elements.submitBtn.textContent =
                    config.i18n.saveLabel || config.i18n.save || "";
            }
            setNotice("", "");
        }

        function deleteSchedule(id) {
            apiFetch({
                url: config.restUrl + "/" + id,
                method: "DELETE",
                headers: {
                    "X-WP-Nonce": config.nonce,
                },
            })
                .then(function () {
                    setNotice("success", config.i18n.deleted);
                    fetchSchedules();
                })
                .catch(function () {
                    setNotice("error", config.i18n.error);
                });
        }

        function runSchedule(id) {
            apiFetch({
                url: config.restUrl + "/" + id + "/run",
                method: "POST",
                headers: {
                    "X-WP-Nonce": config.nonce,
                },
            })
                .then(function () {
                    setNotice("success", config.i18n.run);
                    fetchSchedules();
                })
                .catch(function () {
                    setNotice("error", config.i18n.error);
                });
        }

        function validateSchedulePayload(payload) {
            const title = (payload.postTitle || "").trim();
            if (!title) {
                return (
                    config.i18n.titleRequired ||
                    config.i18n.error ||
                    "Title is required."
                );
            }

            const authorId = parseInt(payload.authorId || "", 10);
            if (Number.isNaN(authorId) || authorId <= 0) {
                return (
                    config.i18n.authorRequired ||
                    config.i18n.error ||
                    "Author is required."
                );
            }

            if (!(payload.publishAt || "").trim()) {
                return (
                    config.i18n.dateRequired ||
                    config.i18n.error ||
                    "Publish date is required."
                );
            }

            return "";
        }

        function formatStatus(status) {
            if (
                config.i18n &&
                config.i18n.statusMap &&
                config.i18n.statusMap[status]
            ) {
                return config.i18n.statusMap[status];
            }

            return status;
        }

        function toDateInputValue(value) {
            if (!value) {
                return "";
            }

            const date = new Date(value);

            if (Number.isNaN(date.getTime())) {
                return "";
            }

            const pad = function (number) {
                return String(number).padStart(2, "0");
            };

            return (
                date.getFullYear() +
                "-" +
                pad(date.getMonth() + 1) +
                "-" +
                pad(date.getDate()) +
                "T" +
                pad(date.getHours()) +
                ":" +
                pad(date.getMinutes())
            );
        }

        function escapeHtml(text) {
            if (!text) {
                return "";
            }

            return text.replace(/[&<>"']/g, function (match) {
                const map = {
                    "&": "&amp;",
                    "<": "&lt;",
                    ">": "&gt;",
                    '"': "&quot;",
                    "'": "&#039;",
                };
                return map[match];
            });
        }

        function debounce(callback, delay) {
            let timeout = null;

            return function (...args) {
                window.clearTimeout(timeout);
                timeout = window.setTimeout(function () {
                    callback.apply(null, args);
                }, delay);
            };
        }
    }

    function initPrimaryLinksRepeater() {
        const containers = document.querySelectorAll(
            "[data-kasumi-primary-links]",
        );

        if (!containers.length) {
            return;
        }

        containers.forEach(function (container) {
            const body = container.querySelector(
                "[data-kasumi-primary-links-body]",
            );
            const templateSelector = container.getAttribute("data-template");
            const template = templateSelector
                ? document.querySelector(templateSelector)
                : null;
            let nextIndex = parseInt(
                container.getAttribute("data-next-index"),
                10,
            );

            if (Number.isNaN(nextIndex)) {
                nextIndex = body ? body.children.length : 0;
            }

            container.addEventListener("click", function (event) {
                const addButton = event.target.closest(
                    '[data-action="add-primary-link"]',
                );
                if (addButton) {
                    event.preventDefault();
                    addRow();
                    return;
                }

                const removeButton = event.target.closest(
                    '[data-action="remove-primary-link"]',
                );
                if (removeButton) {
                    event.preventDefault();
                    const row = removeButton.closest(
                        ".kasumi-primary-links-row",
                    );
                    if (row) {
                        row.remove();
                    }
                    return;
                }

                const fillButton = event.target.closest(
                    '[data-action="fill-primary-link"]',
                );
                if (fillButton) {
                    event.preventDefault();
                    const row = fillButton.closest(".kasumi-primary-links-row");
                    if (!row) {
                        return;
                    }

                    const select = row.querySelector(
                        "[data-primary-link-select]",
                    );
                    const input = row.querySelector("[data-link-url]");

                    if (!select || !input || !select.value) {
                        const message =
                            window.kasumiAiAdmin &&
                            window.kasumiAiAdmin.i18n &&
                            window.kasumiAiAdmin.i18n.primaryLinkSelect
                                ? window.kasumiAiAdmin.i18n.primaryLinkSelect
                                : "Wybierz stronę z listy.";
                        window.alert(message);
                        return;
                    }

                    input.value = select.value;
                }
            });

            function addRow() {
                if (!template || !body) {
                    return;
                }

                const html = template.innerHTML.replace(
                    /__INDEX__/g,
                    nextIndex,
                );
                nextIndex++;

                const wrapper = document.createElement("tbody");
                wrapper.innerHTML = html;
                while (wrapper.firstElementChild) {
                    body.appendChild(wrapper.firstElementChild);
                }

                container.setAttribute("data-next-index", String(nextIndex));
            }
        });
    }

    function initCanvasPresetControl() {
        const select = document.querySelector(
            "[data-kasumi-canvas-preset]",
        );

        if (!select) {
            return;
        }

        const widthField = document.querySelector(
            'input[name="kasumi_ai_options[image_canvas_width]"]',
        );
        const heightField = document.querySelector(
            'input[name="kasumi_ai_options[image_canvas_height]"]',
        );

        select.addEventListener("change", function () {
            const option =
                select.options[select.selectedIndex] || null;

            if (!option) {
                return;
            }

            const width = option.getAttribute("data-width");
            const height = option.getAttribute("data-height");

            if (widthField && width) {
                widthField.value = width;
            }

            if (heightField && height) {
                heightField.value = height;
            }
        });
    }
})(window.jQuery);
