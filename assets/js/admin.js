/**
 * Schema Generator Pro - Admin JavaScript
 */

/* global jQuery, sgAdmin */
(function ($) {
    'use strict';

    const SG = {
        pollTimer: null,

        init: function () {
            $(document).on('click', '#sg-fetch-schema', this.fetchSchema.bind(this));
            $(document).on('click', '#sg-save-all-mappings', this.saveAllMappings.bind(this));
            $(document).on('input', '.sg-type-search-input', this.handleTypeSearch.bind(this));
            $(document).on('click', '.sg-type-result-item', this.selectType.bind(this));

            // Load mapping table when schema type changes (on blur after edit, or after search select)
            $(document).on('change', '.sg-type-search-input', function () {
                var $row = $(this).closest('.sg-mapping-row');
                var $container = $row.find('.sg-mapping-table-container');
                $container.empty();

                var postType = $(this).data('post-type');
                var schemaType = $(this).val().trim();
                if (postType && schemaType) {
                    SG.loadPropertiesForMapping(postType, schemaType, $container);
                    // Auto-save the schema type so enabled + type works immediately for output
                    SG.saveSchemaTypeOnly(postType, schemaType);
                }
            });

            // New: Load properties button for advanced mapping
            $(document).on('click', '.sg-load-properties', function (e) {
                var $btn = $(this);
                var postType = $btn.data('post-type');
                var schemaType = $btn.data('schema-type') || $btn.closest('.sg-mapping-row').find('.sg-type-search-input').val();
                var $container = $btn.siblings('.sg-mapping-table-container');

                if (!schemaType) {
                    alert('Please select a Schema Type first.');
                    return;
                }

                SG.loadPropertiesForMapping(postType, schemaType, $container);
            });

            // Auto-save enabled post types when checkboxes change
            $(document).on('change', '.sg-enabled-pt, input[name*="enabled_post_types"]', function () {
                var enabled = [];
                $('.sg-enabled-pt, input[name*="enabled_post_types"]').each(function () {
                    if ($(this).is(':checked')) {
                        var val = $(this).val();
                        if (enabled.indexOf(val) === -1) enabled.push(val);
                    }
                });
                SG.ajax('sg_save_mappings', { enabled: JSON.stringify(enabled) }, function () {
                    // silent
                });
            });

            // Dedicated button to save enabled and reload so mapping rows appear
            $(document).on('click', '#sg-enable-and-reload', function () {
                var enabled = [];
                $('.sg-enabled-pt, input[name*="enabled_post_types"]').each(function () {
                    if ($(this).is(':checked')) {
                        var val = $(this).val();
                        if (enabled.indexOf(val) === -1) enabled.push(val);
                    }
                });
                SG.ajax('sg_save_mappings', { enabled: JSON.stringify(enabled) }, function (response) {
                    window.location.reload();
                });
            });

            $(document).on('click', function (e) {
                if (!$(e.target).closest('.sg-type-search-input').length &&
                    !$(e.target).closest('.sg-type-search-results').length) {
                    $('.sg-type-search-results').empty();
                }
            });

            $('#sg-type-search').on('input', this.handleDictionarySearch.bind(this));

            // Clear validation log button
            $(document).on('click', '#sg-clear-validation-log', function () {
                var $btn = $(this);
                $btn.prop('disabled', true).text('Clearing...');
                SG.ajax('sg_clear_validation_log', {}, function (response) {
                    if (response.success) {
                        $('#sg-validation-log').html(
                            '<p class="description">' +
                            'Validation log cleared.' +
                            '</p>'
                        );
                    } else {
                        $btn.prop('disabled', false).text('Clear Log');
                        alert(response.data.message || 'Failed to clear log.');
                    }
                });
            });

            // Normalize mappings button (in Settings page)
            $(document).on('click', '#sg-normalize-mappings', function () {
                var $btn = $(this);
                var $status = $('#sg-normalize-status');
                $btn.prop('disabled', true).text('Normalizing...');
                $status.text('Processing...');

                SG.ajax('sg_normalize_mappings', {}, function (response) {
                    if (response.success) {
                        $status.text(response.data.message || 'Done!').css('color', '#00a32a');
                        $btn.text('Normalization Complete');
                    } else {
                        $status.text(response.data.message || 'Failed.').css('color', '#d63638');
                        $btn.prop('disabled', false).text('Try Again');
                    }
                });
            });

            // ===== Schema Templates =====

            // Type search for template form.
            $(document).on('input', '#sg-template-type', function () {
                var $input = $(this);
                var query = $input.val().trim();
                var $results = $('#sg-template-type-results');

                if (query.length < 2) {
                    $results.empty();
                    return;
                }

                clearTimeout(SG.tmplTypeTimer);
                SG.tmplTypeTimer = setTimeout(function () {
                    SG.ajax('sg_search_types', { query: query }, function (response) {
                        if (response.success && response.data.types) {
                            $results.empty();
                            response.data.types.forEach(function (type) {
                                $results.append(
                                    '<div class="sg-type-result-item" data-type-id="' + type.id + '" ' +
                                    'style="padding:6px 8px;cursor:pointer;border-bottom:1px solid #eee;">' +
                                    '<strong>' + type.label + '</strong> ' +
                                    '<small style="color:#666;">' + type.id + '</small>' +
                                    '</div>'
                                );
                            });
                        }
                    });
                }, 300);
            });

            $(document).on('click', '#sg-template-type-results .sg-type-result-item', function () {
                var typeId = $(this).data('type-id');
                $('#sg-template-type').val(typeId);
                $('#sg-template-type-results').empty();
                SG.loadTemplateProperties(typeId);
            });

            // Load properties for template form.
            SG.loadTemplateProperties = function (schemaType) {
                if (!schemaType) {
                    $('#sg-template-properties-container').html('<p class="description">Select a schema type above to load available properties.</p>');
                    return;
                }
                SG.ajax('sg_get_type_properties', { type_slug: schemaType }, function (response) {
                    if (response.success && response.data.properties && response.data.properties.length > 0) {
                        SG.renderTemplateProperties(response.data.properties);
                    } else {
                        SG.renderTemplateProperties([
                            {id: 'headline', label: 'headline', description: 'Headline or title'},
                            {id: 'name', label: 'name', description: 'Name'},
                            {id: 'description', label: 'description', description: 'Description'},
                            {id: 'image', label: 'image', description: 'Image'},
                            {id: 'author', label: 'author', description: 'Author'},
                            {id: 'datePublished', label: 'datePublished', description: 'Date published'},
                            {id: 'dateModified', label: 'dateModified', description: 'Date modified'},
                            {id: 'url', label: 'url', description: 'URL'},
                        ]);
                    }
                });
            };

            SG.renderTemplateProperties = function (properties) {
                var html = '<table class="widefat"><thead><tr><th>Property</th><th>Map From</th><th>Config</th></tr></thead><tbody>';
                var sources = [
                    {value: '', label: '— Not mapped —'},
                    {value: 'title', label: 'Post Title'},
                    {value: 'excerpt', label: 'Excerpt'},
                    {value: 'content', label: 'Full Content'},
                    {value: 'featured_image', label: 'Featured Image'},
                    {value: 'author', label: 'Author (Person)'},
                    {value: 'author_name', label: 'Author Name'},
                    {value: 'date_published', label: 'Date Published'},
                    {value: 'date_modified', label: 'Date Modified'},
                    {value: 'permalink', label: 'Permalink'},
                    {value: 'meta', label: 'Custom Meta'},
                    {value: 'static', label: 'Static Value'},
                    {value: 'categories', label: 'Categories'},
                    {value: 'tags', label: 'Tags'},
                    {value: 'site_name', label: 'Site Name'},
                    {value: 'site_url', label: 'Site URL'},
                ];

                properties.forEach(function (prop) {
                    var rawId = prop.id || prop.schema_id || '';
                    var propId = rawId.replace(/^schema:/i, '').replace(/^schema/i, '');
                    var label = prop.label || propId;

                    html += '<tr class="sg-tmpl-prop-row" data-prop="' + propId + '">';
                    html += '<td><strong>' + label + '</strong><br><small style="color:#666;">' + propId + '</small></td>';
                    html += '<td><select class="sg-tmpl-source" name="tmpl_prop_' + propId + '_source">';
                    sources.forEach(function (s) {
                        html += '<option value="' + s.value + '">' + s.label + '</option>';
                    });
                    html += '</select></td>';
                    html += '<td>';
                    html += '<input type="text" class="sg-tmpl-meta-key" name="tmpl_prop_' + propId + '_meta_key" placeholder="meta_key" style="display:none;width:140px;">';
                    html += '<input type="text" class="sg-tmpl-static" name="tmpl_prop_' + propId + '_static" placeholder="Static value" style="display:none;width:140px;">';
                    html += '</td>';
                    html += '</tr>';
                });

                html += '</tbody></table>';
                $('#sg-template-properties-container').html(html);

                // Show/hide meta/static fields.
                $(document).on('change', '.sg-tmpl-source', function () {
                    var $row = $(this).closest('tr');
                    var val = $(this).val();
                    $row.find('.sg-tmpl-meta-key, .sg-tmpl-static').hide();
                    if (val === 'meta') $row.find('.sg-tmpl-meta-key').show();
                    if (val === 'static') $row.find('.sg-tmpl-static').show();
                });
            };

            // Save template.
            $('#sg-template-form').on('submit', function (e) {
                e.preventDefault();
                var $btn = $('#sg-save-template');
                var $status = $('#sg-template-save-status');
                $btn.prop('disabled', true).text('Saving...');
                $status.text('');

                // Collect properties.
                var properties = {};
                $('.sg-tmpl-prop-row').each(function () {
                    var propId = $(this).data('prop');
                    var source = $(this).find('.sg-tmpl-source').val();
                    if (source) {
                        properties[propId] = { source: source };
                        if (source === 'meta') {
                            properties[propId].meta_key = $(this).find('.sg-tmpl-meta-key').val();
                        }
                        if (source === 'static') {
                            properties[propId].static_value = $(this).find('.sg-tmpl-static').val();
                        }
                    }
                });

                var postTypes = [];
                $('#sg-template-form input[name="post_types[]"]:checked').each(function () {
                    postTypes.push($(this).val());
                });

                SG.ajax('sg_save_template', {
                    id: $('#sg-template-id').val(),
                    name: $('#sg-template-name').val(),
                    schema_type: $('#sg-template-type').val(),
                    post_types: JSON.stringify(postTypes),
                    properties: JSON.stringify(properties),
                }, function (response) {
                    $btn.prop('disabled', false).text('Save Template');
                    if (response.success) {
                        $status.text(response.data.message).css('color', '#00a32a');
                        setTimeout(function () { window.location.reload(); }, 800);
                    } else {
                        $status.text(response.data.message || 'Failed.').css('color', '#d63638');
                    }
                });
            });

            // Edit template.
            $(document).on('click', '.sg-edit-template', function () {
                var id = $(this).data('id');
                SG.ajax('sg_get_template', { id: id }, function (response) {
                    if (response.success && response.data.template) {
                        var t = response.data.template;
                        $('#sg-template-id').val(t.id);
                        $('#sg-template-name').val(t.name);
                        $('#sg-template-type').val(t.schema_type);
                        $('#sg-template-form-title').text('Edit Template');
                        $('#sg-cancel-template-edit').show();

                        // Check post types.
                        $('#sg-template-form input[name="post_types[]"]').prop('checked', false);
                        if (t.post_types) {
                            t.post_types.forEach(function (pt) {
                                $('#sg-template-form input[name="post_types[]"][value="' + pt + '"]').prop('checked', true);
                            });
                        }

                        // Load properties.
                        SG.loadTemplateProperties(t.schema_type);

                        // Fill property values after a short delay.
                        setTimeout(function () {
                            if (t.properties) {
                                Object.keys(t.properties).forEach(function (propId) {
                                    var cfg = t.properties[propId];
                                    var $row = $('.sg-tmpl-prop-row[data-prop="' + propId + '"]');
                                    if ($row.length && cfg.source) {
                                        $row.find('.sg-tmpl-source').val(cfg.source).trigger('change');
                                        if (cfg.meta_key) $row.find('.sg-tmpl-meta-key').val(cfg.meta_key);
                                        if (cfg.static_value) $row.find('.sg-tmpl-static').val(cfg.static_value);
                                    }
                                });
                            }
                        }, 500);

                        window.scrollTo({ top: $('#sg-template-form').offset().top - 50, behavior: 'smooth' });
                    }
                });
            });

            // Cancel edit.
            $('#sg-cancel-template-edit').on('click', function () {
                $('#sg-template-form')[0].reset();
                $('#sg-template-id').val('');
                $('#sg-template-form-title').text('Create New Template');
                $('#sg-cancel-template-edit').hide();
                $('#sg-template-properties-container').html('<p class="description">Select a schema type above to load available properties.</p>');
            });

            // Delete template.
            $(document).on('click', '.sg-delete-template', function () {
                var id = $(this).data('id');
                if (!confirm('Are you sure you want to delete this template?')) return;
                SG.ajax('sg_delete_template', { id: id }, function (response) {
                    if (response.success) {
                        $('tr[data-template-id="' + id + '"]').fadeOut(300, function () { $(this).remove(); });
                    } else {
                        alert(response.data.message || 'Failed to delete.');
                    }
                });
            });
        },

        ajax: function (action, data, callback) {
            data.action = action;
            data.nonce = sgAdmin.nonce;

            $.post(sgAdmin.ajaxUrl, data, function (response) {
                if (callback) {
                    callback(response);
                }
            }).fail(function () {
                if (callback) {
                    callback({ success: false, data: { message: 'Request failed.' } });
                }
            });
        },

        fetchSchema: function () {
            var $btn = $(this);
            $btn.prop('disabled', true).text('Starting...');
            $('#sg-fetch-status').text('Scheduling background fetch...').css('color', '#666');

            SG.ajax('sg_fetch_schema', {}, function (response) {
                if (response.success) {
                    $('#sg-fetch-status').text(response.data.message).css('color', '#0073aa');
                    SG.startPolling();
                } else {
                    $('#sg-fetch-status').text(response.data.message).css('color', '#d63638');
                    $btn.prop('disabled', false).text('Fetch / Update Schema Dictionary');
                }
            });
        },

        startPolling: function () {
            SG.pollTimer = setInterval(function () {
                SG.ajax('sg_fetch_status', {}, function (response) {
                    if (response.success && response.data.status) {
                        var data = response.data;
                        $('#sg-fetch-status').text(data.message);

                        if (data.status === 'success' || data.status === 'idle') {
                            clearInterval(SG.pollTimer);
                            $('#sg-fetch-schema').prop('disabled', false).text('Fetch / Update Schema Dictionary');
                            $('#sg-fetch-status').css('color', '#00a32a');
                            if (data.count !== undefined) {
                                $('#sg-type-count').text(data.count);
                            }
                        } else if (data.status === 'failed') {
                            clearInterval(SG.pollTimer);
                            $('#sg-fetch-schema').prop('disabled', false).text('Fetch / Update Schema Dictionary');
                            $('#sg-fetch-status').css('color', '#d63638');
                        } else {
                            $('#sg-fetch-status').css('color', '#0073aa');
                        }
                    }
                });
            }, 5000);
        },

        handleTypeSearch: function (e) {
            var $input = $(e.target);
            var query = $input.val().trim();
            var $results = $input.siblings('.sg-type-search-results');
            var timerKey = $input.data('post-type') || 'default';

            if (query.length < 2) {
                $results.empty();
                return;
            }

            clearTimeout(SG['timer_' + timerKey]);
            SG['timer_' + timerKey] = setTimeout(function () {
                SG.ajax('sg_search_types', { query: query }, function (response) {
                    if (response.success && response.data.types) {
                        $results.empty();
                        response.data.types.forEach(function (type) {
                            $results.append(
                                '<div class="sg-type-result-item" data-type-id="' + type.id + '" ' +
                                'style="padding:6px 8px;cursor:pointer;border-bottom:1px solid #eee;">' +
                                '<strong>' + type.label + '</strong> ' +
                                '<small style="color:#666;">' + type.id + '</small>' +
                                '</div>'
                            );
                        });
                    }
                });
            }, 300);
        },

        selectType: function (e) {
            var $item = $(e.target).closest('.sg-type-result-item');
            var typeId = $item.data('type-id');
            var $input = $item.closest('.sg-type-search-results').siblings('.sg-type-search-input');

            $input.val(typeId);
            $item.closest('.sg-type-search-results').empty();

            // Auto trigger mapping load after selecting type
            var $row = $input.closest('.sg-mapping-row');
            var postType = $input.data('post-type');
            var $wrapper = $row.find('.sg-field-mappings');
            var $container = $wrapper.find('.sg-mapping-table-container');
            var $btn = $wrapper.find('.sg-load-properties');

            if (postType && typeId) {
                // Update button data
                if ($btn.length) {
                    $btn.data('schema-type', typeId);
                }
                $container.empty();
                SG.loadPropertiesForMapping(postType, typeId, $container);
                // Auto-save the schema type 
                SG.saveSchemaTypeOnly(postType, typeId);
            }
        },

        handleDictionarySearch: function (e) {
            var query = $(e.target).val().trim();
            var $results = $('#sg-type-search-results');

            if (query.length < 2) {
                $results.empty();
                return;
            }

            clearTimeout(SG.timerDictionary);
            SG.timerDictionary = setTimeout(function () {
                SG.ajax('sg_search_types', { query: query }, function (response) {
                    if (response.success && response.data.types) {
                        $results.empty();
                        response.data.types.forEach(function (type) {
                            $results.append(
                                '<div style="padding:8px;border-bottom:1px solid #eee;">' +
                                '<strong>' + type.label + '</strong> ' +
                                '<code>' + type.id + '</code>' +
                                (type.description ? '<br><small>' + type.description.substring(0, 100) + '...</small>' : '') +
                                '</div>'
                            );
                        });
                    }
                });
            }, 300);
        },

        saveAllMappings: function () {
            var mappings = {};
            var enabled = [];

            // Collect enabled post types from checkboxes
            $('.sg-enabled-pt, input[name*="enabled_post_types"]').each(function () {
                if ($(this).is(':checked')) {
                    var val = $(this).val();
                    if (enabled.indexOf(val) === -1) enabled.push(val);
                }
            });

            $('.sg-mapping-row').each(function () {
                var $row = $(this);
                var postType = $row.find('.sg-type-search-input').data('post-type');
                var schemaType = $row.find('.sg-type-search-input').val();

                if (!postType || !schemaType) {
                    return;
                }

                var fields = {};

                $row.find('.sg-mapping-row-item').each(function () {
                    var $item = $(this);
                    var prop = $item.data('property');
                    var source = $item.find('.sg-source-select').val();
                    var metaKey = $item.find('.sg-meta-key').val() || '';
                    var staticVal = $item.find('.sg-static-value').val() || '';

                    if (source) {
                        fields[prop] = {
                            source: source
                        };
                        if (source === 'meta' && metaKey) {
                            fields[prop].meta_key = metaKey;
                        }
                        if (source === 'static' && staticVal) {
                            fields[prop].static_value = staticVal;
                        }
                    }
                });

                mappings[postType] = {
                    schema_type: schemaType,
                    fields: fields
                };
            });

            SG.ajax('sg_save_mappings', { 
                mappings: JSON.stringify(mappings),
                enabled: JSON.stringify(enabled)
            }, function (response) {
                if (response.success) {
                    alert('Mappings saved successfully!');
                    // Reload so the UI shows the new enabled post type rows + mappings
                    window.location.reload();
                } else {
                    alert(response.data.message || 'Save failed.');
                }
            });
        },

        // Load properties for a post type and render the mapping table
        loadPropertiesForMapping: function (postType, schemaType, $container) {
            if (!$container || $container.length === 0) {
                // Safety: create container if not present in DOM
                var $wrapper = $('.sg-field-mappings[data-post-type="' + postType + '"]');
                $container = $wrapper.find('.sg-mapping-table-container');
                if ($container.length === 0) {
                    $container = $('<div class="sg-mapping-table-container" style="margin-top:15px;"></div>');
                    $wrapper.append($container);
                }
            }
            $container.html('<p>Loading properties from Schema.org vocabulary...</p>');

            if (typeof sgAdmin === 'undefined' || !sgAdmin.nonce) {
                $container.html('<p style="color:red;">JS not loaded properly. Reload the page.</p>');
                return;
            }

            $.post(sgAdmin.ajaxUrl, {
                action: 'sg_get_type_properties',
                nonce: sgAdmin.nonce,
                type_slug: schemaType
            }, function (response) {
                if (response.success && response.data.properties && response.data.properties.length > 0) {
                    var $parentRow = $container.closest('.sg-field-mappings');
                    var saved = {};
                    try {
                        saved = JSON.parse($parentRow.attr('data-existing-mappings') || '{}');
                    } catch(e) {}

                    SG.renderMappingTable(postType, response.data.properties, $container, saved);
                } else {
                    // Fallback basic mapping table so it "works" even without full dictionary
                    var basicProps = [
                        {id: 'headline', label: 'Headline', description: 'Main title'},
                        {id: 'description', label: 'Description', description: 'Summary'},
                        {id: 'image', label: 'Image', description: 'Main image'},
                        {id: 'author', label: 'Author', description: 'Creator'},
                        {id: 'datePublished', label: 'Date Published', description: ''},
                        {id: 'url', label: 'URL', description: ''},
                    ];
                    var $parentRow = $container.closest('.sg-field-mappings');
                    var saved = {};
                    try {
                        saved = JSON.parse($parentRow.attr('data-existing-mappings') || '{}');
                    } catch(e) {}
                    SG.renderMappingTable(postType, basicProps, $container, saved);
                    $container.append('<p class="description" style="color:orange;">Using basic properties (fetch dictionary for full list from Schema.org).</p>');
                }
            });
        },

        renderMappingTable: function (postType, properties, $container, savedMappings) {
            savedMappings = savedMappings || {};

            var html = '<table class="widefat" style="margin-top:10px;">';
            html += '<thead><tr><th>Schema Property</th><th>Description</th><th>Map From</th><th>Configuration</th></tr></thead><tbody>';

            var commonSources = [
                {value: '', label: '— Not mapped —'},
                {value: 'title', label: 'Post Title'},
                {value: 'excerpt', label: 'Excerpt'},
                {value: 'content', label: 'Full Content'},
                {value: 'featured_image', label: 'Featured Image (rich ImageObject)'},
                {value: 'author', label: 'Author (as Person)'},
                {value: 'date_published', label: 'Date Published'},
                {value: 'date_modified', label: 'Date Modified'},
                {value: 'permalink', label: 'Permalink / URL'},
                {value: 'meta', label: 'Custom Meta Field'},
                {value: 'static', label: 'Static / Hardcoded Value'},
                {value: 'categories', label: 'Categories'},
                {value: 'tags', label: 'Tags'},
            ];

            properties.forEach(function (prop) {
                var rawId = prop.id || prop.schema_id || prop['@id'] || '';
                var propId = rawId.replace(/^schema:/i, '').replace(/^schema/i, '');
                var label = prop.label || propId;
                var desc = (prop.description || '').substring(0, 140) + (prop.description && prop.description.length > 140 ? '...' : '');

                var saved = savedMappings[propId] || {};
                var currentSource = saved.source || '';

                // Smart defaults if nothing saved
                if (!currentSource) {
                    var lowerProp = propId.toLowerCase().replace('schema:', '');
                    if (lowerProp.includes('name') || lowerProp.includes('headline') || lowerProp.includes('title')) {
                        currentSource = 'title';
                    } else if (lowerProp.includes('image') || lowerProp.includes('photo') || lowerProp.includes('thumbnail')) {
                        currentSource = 'featured_image';
                    } else if (lowerProp.includes('author') || lowerProp === 'creator') {
                        currentSource = 'author';
                    } else if (lowerProp.includes('datepublished') || lowerProp.includes('date')) {
                        currentSource = 'date_published';
                    } else if (lowerProp.includes('description') || lowerProp.includes('articlebody')) {
                        currentSource = 'excerpt';
                    } else if (lowerProp.includes('url') || lowerProp.includes('permalink')) {
                        currentSource = 'permalink';
                    }
                }

                html += '<tr class="sg-mapping-row-item" data-property="' + propId + '">';
                html += '<td><strong>' + label + '</strong><br><small style="color:#666;">' + propId + '</small></td>';
                html += '<td><small>' + desc + '</small></td>';

                html += '<td><select class="sg-source-select">';
                commonSources.forEach(function (src) {
                    var selected = (src.value === currentSource) ? 'selected' : '';
                    html += '<option value="' + src.value + '" ' + selected + '>' + src.label + '</option>';
                });
                html += '</select></td>';

                var metaVal = saved.meta_key || '';
                var staticVal = saved.static_value || '';

                html += '<td>';
                html += '<input type="text" class="sg-meta-key regular-text" placeholder="meta_key" value="' + metaVal + '" style="' + (currentSource === 'meta' ? '' : 'display:none;') + 'width: 180px;">';
                html += '<input type="text" class="sg-static-value regular-text" placeholder="Static value" value="' + staticVal + '" style="' + (currentSource === 'static' ? '' : 'display:none;') + 'width: 180px;">';
                html += '</td>';

                html += '</tr>';
            });

            html += '</tbody></table>';
            html += '<p class="description" style="margin-top:8px;">💡 Map "image", "photo" or "thumbnail" → Featured Image for full ImageObject. "author" → rich Person. Use "meta" for custom fields.</p>';

            $container.html(html);

            // Dynamic show/hide for extra config fields
            $container.on('change', '.sg-source-select', function () {
                var $tr = $(this).closest('tr');
                var val = $(this).val();
                $tr.find('.sg-meta-key, .sg-static-value').hide();
                if (val === 'meta') $tr.find('.sg-meta-key').show();
                if (val === 'static') $tr.find('.sg-static-value').show();
            });
        },

        // Helper to auto-save just the schema type for a post type (so basic output works immediately)
        saveSchemaTypeOnly: function (postType, schemaType) {
            if (typeof sgAdmin === 'undefined') return;
            var mappings = {};
            mappings[postType] = {
                schema_type: schemaType,
                fields: {}
            };
            // Collect current + ensure this postType is enabled
            var enabled = [];
            $('.sg-enabled-pt, input[name*="enabled_post_types"]').each(function () {
                if ($(this).is(':checked')) {
                    var val = $(this).val();
                    if (enabled.indexOf(val) === -1) enabled.push(val);
                }
            });
            if (enabled.indexOf(postType) === -1) {
                enabled.push(postType);
            }
            SG.ajax('sg_save_mappings', { 
                mappings: JSON.stringify(mappings),
                enabled: JSON.stringify(enabled)
            }, function (response) {
                // silent save for type
                if (!response.success) {
                    console.log('Auto save schema type failed');
                }
            });
        }
    };

    $(document).ready(function () {
        SG.init();

        // Auto-load mapping tables for rows that have a schema type set (from saved or input)
        // Also bootstrap common post types with a default schema type if none set
        $('.sg-field-mappings').each(function () {
            var $wrapper = $(this);
            var postType = $wrapper.data('post-type');
            var $input = $wrapper.closest('.sg-mapping-row').find('.sg-type-search-input');
            var schemaType = $wrapper.find('.sg-load-properties').data('schema-type') || $input.val().trim();
            var $container = $wrapper.find('.sg-mapping-table-container');
            if (postType && schemaType && $container.length) {
                setTimeout(function () {
                    SG.loadPropertiesForMapping(postType, schemaType, $container);
                }, 300);
            } else if (postType && !schemaType && (postType === 'post' || postType === 'page') && $input.length && $container.length && typeof sgAdmin !== 'undefined') {
                // Bootstrap default for common types
                var defaultType = 'Article';
                $input.val(defaultType);
                // Auto check the enabled checkbox for this type
                $('.sg-enabled-pt[value="' + postType + '"]').prop('checked', true);
                setTimeout(function () {
                    SG.saveSchemaTypeOnly(postType, defaultType);
                    SG.loadPropertiesForMapping(postType, defaultType, $container);
                }, 500);
            }
        });
    });

})(jQuery);
