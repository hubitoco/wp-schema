/**
 * Schema Generator Pro - Multi-Schema Metabox JavaScript
 *
 * Handles per-post schema management in the block/classic editor.
 * Users can search for schema types, auto-load all properties from the
 * vocabulary DB, and map each property to WordPress data sources.
 */

/* global jQuery */
(function ($) {
    'use strict';

    const SGMetabox = {
        ajaxUrl: '',
        nonce: '',
        postId: 0,
        schemaIndex: 0,
        initialized: false,

        // All available mapping sources (same as template page).
        sources: [
            { value: '',             label: '— Not mapped —' },
            { value: 'title',        label: 'Post Title' },
            { value: 'excerpt',      label: 'Excerpt' },
            { value: 'content',      label: 'Full Content' },
            { value: 'featured_image', label: 'Featured Image (ImageObject)' },
            { value: 'author',       label: 'Author (Person object)' },
            { value: 'author_name',  label: 'Author Name' },
            { value: 'author_url',   label: 'Author URL' },
            { value: 'date_published', label: 'Date Published' },
            { value: 'date_modified',  label: 'Date Modified' },
            { value: 'permalink',    label: 'Permalink / URL' },
            { value: 'meta',         label: 'Custom Meta Field' },
            { value: 'static',       label: 'Static Value' },
            { value: 'categories',   label: 'Categories' },
            { value: 'tags',         label: 'Tags' },
            { value: 'site_name',    label: 'Site Name' },
            { value: 'site_url',     label: 'Site URL' },
        ],

        // Built-in catalog of common Schema.org types. The metabox searches this
        // locally so type search always works even if the full Schema.org
        // dictionary has not been fetched into the database yet.
        schemaTypeCatalog: [
            'Article', 'BlogPosting', 'NewsArticle', 'TechArticle', 'Report',
            'WebPage', 'WebSite', 'AboutPage', 'ContactPage', 'CollectionPage',
            'Product', 'Offer', 'AggregateOffer', 'Review', 'AggregateRating',
            'Recipe', 'HowTo', 'FAQPage', 'QAPage', 'Question',
            'Event', 'Course', 'JobPosting', 'Service', 'SoftwareApplication',
            'Organization', 'LocalBusiness', 'Restaurant', 'Store', 'Corporation',
            'Person', 'Book', 'Movie', 'VideoObject', 'AudioObject',
            'ImageObject', 'BreadcrumbList', 'ItemList', 'Place', 'PostalAddress'
        ],

        // Default WordPress data source for well-known Schema.org properties.
        // Used to auto-map properties the moment a type is selected.
        defaultSourceMap: {
            name:               'title',
            headline:           'title',
            alternativeHeadline:'title',
            description:        'excerpt',
            abstract:           'excerpt',
            articleBody:        'content',
            text:               'content',
            image:              'featured_image',
            thumbnailUrl:       'featured_image',
            author:             'author',
            creator:            'author',
            datePublished:      'date_published',
            dateCreated:        'date_published',
            dateModified:       'date_modified',
            url:                'permalink',
            mainEntityOfPage:   'permalink',
            keywords:           'tags',
            articleSection:     'categories',
            publisher:          'site_name',
            copyrightHolder:    'site_name'
        },

        // Curated property sets per type, so selecting a type instantly produces
        // a useful, pre-mapped table. Falls back to "_default" for unknown types.
        typePropertyMap: {
            _default:    ['name', 'description', 'image', 'url'],
            Article:     ['headline', 'description', 'image', 'author', 'datePublished', 'dateModified', 'url', 'articleBody', 'keywords', 'publisher'],
            BlogPosting: ['headline', 'description', 'image', 'author', 'datePublished', 'dateModified', 'url', 'articleBody', 'keywords', 'publisher'],
            NewsArticle: ['headline', 'description', 'image', 'author', 'datePublished', 'dateModified', 'url', 'articleBody', 'articleSection', 'publisher'],
            WebPage:     ['name', 'description', 'image', 'url', 'datePublished', 'dateModified'],
            WebSite:     ['name', 'description', 'url'],
            Product:     ['name', 'description', 'image', 'url', 'sku', 'brand', 'offers'],
            Recipe:      ['name', 'description', 'image', 'author', 'datePublished', 'keywords', 'recipeCategory', 'recipeIngredient', 'recipeInstructions'],
            FAQPage:     ['name', 'description', 'url'],
            Event:       ['name', 'description', 'image', 'url', 'startDate', 'endDate', 'location'],
            Organization:['name', 'description', 'url', 'logo'],
            LocalBusiness:['name', 'description', 'image', 'url', 'telephone', 'address', 'openingHours'],
            Person:      ['name', 'description', 'image', 'url'],
            VideoObject: ['name', 'description', 'thumbnailUrl', 'uploadDate', 'url']
        },

        /**
         * Filter the built-in type catalog by a query string.
         */
        searchTypes: function (query) {
            query = (query || '').toLowerCase();
            return this.schemaTypeCatalog.filter(function (t) {
                return t.toLowerCase().indexOf(query) !== -1;
            }).slice(0, 15);
        },

        /**
         * Merge two arrays of type names, de-duplicated (case-insensitive),
         * preserving order (local first), capped at `limit`.
         */
        mergeUnique: function (a, b, limit) {
            var seen = {};
            var out  = [];
            [a, b].forEach(function (arr) {
                (arr || []).forEach(function (t) {
                    var key = String(t).toLowerCase();
                    if (t && !seen[key]) { seen[key] = true; out.push(t); }
                });
            });
            return limit ? out.slice(0, limit) : out;
        },

        /**
         * Render a list of type names into a results dropdown, always offering
         * the typed text as a usable custom type.
         */
        renderTypeResults: function ($results, types, query) {
            var self = this;
            $results.empty();

            types.forEach(function (type) {
                $results.append(
                    '<div class="sg-type-result-item" data-type-id="' + self.escHtml(type) + '" ' +
                    'style="padding:6px 8px;cursor:pointer;border-bottom:1px solid #eee;">' +
                    '<strong>' + self.escHtml(type) + '</strong>' +
                    '</div>'
                );
            });

            var exact = types.some(function (t) { return t.toLowerCase() === query.toLowerCase(); });
            if (!exact && query) {
                $results.append(
                    '<div class="sg-type-result-item" data-type-id="' + self.escHtml(query) + '" ' +
                    'style="padding:6px 8px;cursor:pointer;border-top:1px solid #ddd;background:#f6f7f7;">' +
                    'Use custom type: <strong>' + self.escHtml(query) + '</strong>' +
                    '</div>'
                );
            }

            $results.show();
        },

        /**
         * Get the curated property list for a type (with a generic fallback).
         */
        getPropertiesForType: function (type) {
            var list = this.typePropertyMap[type] || this.typePropertyMap._default;
            return list.map(function (p) { return { id: p, label: p }; });
        },

        /**
         * Escape a string for safe insertion into HTML / attributes.
         */
        escHtml: function (str) {
            return String(str).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        },

        /**
         * Initialize — called multiple times if needed.
         */
        init: function () {
            // Bind delegated events FIRST and unconditionally. They live on
            // `document`, so they work for the metabox even if it is added or
            // rendered after this runs (block editor timing). This guarantees
            // type search / mapping work regardless of when the box appears.
            if (!this.initialized) {
                this.bindEvents();
                this.initialized = true;
            }

            var $metabox = $('#sg-schemas-metabox');
            if (!$metabox.length) {
                return;
            }

            // Config: prefer the localized object, fall back to data attributes.
            var cfg = window.sgMetabox || window.sgMetaBox || {};
            this.ajaxUrl = cfg.ajaxUrl || $metabox.data('ajaxurl') || this.ajaxUrl || '';
            this.nonce   = cfg.nonce   || $metabox.data('nonce')   || this.nonce   || '';
            this.postId  = $metabox.data('post-id') || this.postId || 0;
            this.schemaIndex = $metabox.find('.sg-schema-card').length;

            // Initialize editors for any cards that don't have them yet.
            $metabox.find('.sg-schema-editor-wrap:not(:has(.sg-schema-editor))').each(function () {
                var uuid = $(this).data('uuid') || 'schema_' + Date.now();
                SGMetabox.initEditor($(this), uuid);
            });
        },

        bindEvents: function () {
            var self = this;

            // Toggle schema card open/close on header click.
            $(document).on('click', '.sg-schema-card-header', function (e) {
                // Don't toggle when clicking interactive elements.
                var tag = e.target.tagName.toLowerCase();
                if (tag === 'input' || tag === 'button' || tag === 'a' || tag === 'select' || tag === 'textarea') return;
                if ($(e.target).closest('button, a, label').length) return;
                var $card = $(this).closest('.sg-schema-card');
                $card.find('.sg-schema-card-body').slideToggle(200);
            });

            // Edit schema button.
            $(document).on('click', '.sg-edit-schema', function (e) {
                e.stopPropagation();
                e.preventDefault();
                $(this).closest('.sg-schema-card').find('.sg-schema-card-body').slideDown(200);
            });

            // Remove schema button.
            $(document).on('click', '.sg-remove-schema', function (e) {
                e.stopPropagation();
                $(this).closest('.sg-schema-card').fadeOut(200, function () { $(this).remove(); });
            });

            // Add custom schema button.
            $(document).on('click', '#sg-add-schema', function () {
                self.addSchemaCard('Custom Schema');
            });

            // Add from template button.
            $(document).on('click', '#sg-add-from-template', function () {
                var $templates = $('.sg-schema-template');
                if ($templates.length === 0) {
                    alert('No templates available for this post type.');
                    return;
                }
                $templates.each(function () {
                    self.addSchemaFromTemplate($(this));
                });
            });

            // ===== Schema type search =====
            // Instant results from the built-in catalog, then augmented with the
            // full Schema.org vocabulary from the DB (all ~800 types) if loaded.
            $(document).on('input focus', '.sg-schema-type-search', function () {
                var $input   = $(this);
                // Accept a leading "schema:" prefix when searching.
                var query    = $input.val().trim().replace(/^schema:/i, '');
                // The results <div> is ejected from the surrounding <p> by the
                // HTML parser, so scope the lookup to the editor, not siblings.
                var $results = $input.closest('.sg-schema-editor').find('.sg-type-search-results');

                if (query.length < 1) {
                    $results.empty().hide();
                    return;
                }

                // 1. Instant local catalog results.
                self.renderTypeResults($results, self.searchTypes(query), query);

                // 2. Augment with the server's full vocabulary (debounced).
                clearTimeout(self.typeSearchTimer);
                self.typeSearchTimer = setTimeout(function () {
                    if (!self.ajaxUrl) { return; }
                    $.post(self.ajaxUrl, {
                        action: 'sg_search_types',
                        nonce:  self.nonce,
                        query:  query
                    }, function (response) {
                        if (!response || !response.success || !response.data || !response.data.types) { return; }
                        // Only update if the field still holds this query.
                        if ($input.val().trim().replace(/^schema:/i, '') !== query) { return; }
                        var serverTypes = response.data.types.map(function (t) {
                            return String(t.label || t.id || '').replace(/^schema:/i, '');
                        });
                        var merged = self.mergeUnique(self.searchTypes(query), serverTypes, 25);
                        self.renderTypeResults($results, merged, query);
                    });
                }, 250);
            });

            // Select type → load properties.
            $(document).on('click', '.sg-type-search-results .sg-type-result-item', function () {
                // Accept "schema:QAPage" or "QAPage" — store the bare type name.
                var typeId  = String($(this).data('type-id')).replace(/^schema:/i, '').trim();
                var $editor = $(this).closest('.sg-schema-editor');
                var $card   = $(this).closest('.sg-schema-card');

                $editor.find('.sg-schema-type-search').val(typeId);
                $editor.find('.sg-schema-type-input').val(typeId);
                $card.find('.sg-schema-type-name').text(typeId);
                $(this).closest('.sg-type-search-results').empty().hide();
                // Load the FULL property set (with inheritance) from the
                // vocabulary DB; auto-mapping is applied per row on render.
                self.loadProperties($editor, typeId);
            });

            // Source change → show/hide meta/static.
            $(document).on('change', '.sg-prop-source', function () {
                var $row = $(this).closest('.sg-property-row');
                var val  = $(this).val();
                $row.find('.sg-prop-meta-key').toggle(val === 'meta');
                $row.find('.sg-prop-static').toggle(val === 'static');
            });

            // Add property row.
            $(document).on('click', '.sg-add-property', function () {
                // The button is a sibling of the <table>; the rows live in the
                // <tbody class="sg-property-rows"> inside that table.
                var $container = $(this).closest('.sg-schema-properties').find('.sg-property-rows');
                self.addPropertyRow($container, '', '');
            });

            // Remove property row.
            $(document).on('click', '.sg-remove-property', function () {
                $(this).closest('.sg-property-row').remove();
            });

            // Save schemas.
            $(document).on('click', '#sg-save-schemas', function () {
                self.saveSchemas();
            });

            // Close search results on outside click.
            $(document).on('click', function (e) {
                if (!$(e.target).closest('.sg-type-search-results').length &&
                    !$(e.target).closest('.sg-schema-type-search').length) {
                    $('.sg-type-search-results').empty().hide();
                }
            });
        },

        addSchemaCard: function (typeName) {
            var uuid  = 'schema_' + Date.now();
            var index = this.schemaIndex++;
            var $tmpl = $($('#sg-schema-card-template').html()
                .replace(/{{uuid}}/g, uuid)
                .replace(/{{index}}/g, index)
                .replace(/{{type_name}}/g, typeName));

            $tmpl.find('.sg-schema-editor-wrap').attr('data-uuid', uuid);
            $('#sg-schemas-list').append($tmpl);
            $('.sg-no-schemas').hide();
            $tmpl.find('.sg-schema-card-body').show();

            this.initEditor($tmpl.find('.sg-schema-editor-wrap'), uuid);
        },

        addSchemaFromTemplate: function ($templateCard) {
            var type  = $templateCard.find('.sg-schema-type-name').text();
            var uuid  = 'schema_' + Date.now();
            var index = this.schemaIndex++;
            var $tmpl = $($('#sg-schema-card-template').html()
                .replace(/{{uuid}}/g, uuid)
                .replace(/{{index}}/g, index)
                .replace(/{{type_name}}/g, type));

            $tmpl.find('.sg-schema-editor-wrap').attr('data-uuid', uuid);
            $('#sg-schemas-list').append($tmpl);
            $('.sg-no-schemas').hide();
            $tmpl.find('.sg-schema-card-body').show();

            this.initEditor($tmpl.find('.sg-schema-editor-wrap'), uuid);
            this.loadProperties($tmpl.find('.sg-schema-editor'), type);
        },

        initEditor: function ($wrap, uuid) {
            var html =
                '<div class="sg-schema-editor" data-uuid="' + uuid + '">' +
                    '<input type="hidden" class="sg-schema-type-input" name="sg_schemas[' + uuid + '][schema_type]" value="" />' +
                    '<input type="hidden" class="sg-schema-uuid-input" name="sg_schemas[' + uuid + '][uuid]" value="' + uuid + '" />' +
                    '<p>' +
                        '<label><strong>Schema Type:</strong></label>' +
                        '<input type="text" class="sg-schema-type-search widefat" placeholder="Search Schema.org types..." />' +
                        '<div class="sg-type-search-results" style="max-height:150px;overflow-y:auto;display:none;"></div>' +
                    '</p>' +
                    '<div class="sg-schema-properties" style="margin-top:15px;">' +
                        '<h4>Property Mappings</h4>' +
                        '<p class="description" style="margin-bottom:10px;">Select a schema type above to load all its properties.</p>' +
                        '<table class="widefat sg-prop-table">' +
                            '<thead><tr>' +
                                '<th style="width:25%;">Property</th>' +
                                '<th style="width:30%;">Map From</th>' +
                                '<th style="width:35%;">Configuration</th>' +
                                '<th style="width:10%;"></th>' +
                            '</tr></thead>' +
                            '<tbody class="sg-property-rows">' +
                                '<tr class="sg-no-props"><td colspan="4"><em>Select a schema type to load properties.</em></td></tr>' +
                            '</tbody>' +
                        '</table>' +
                        '<button type="button" class="button button-small sg-add-property" style="margin-top:8px;">+ Add Property</button>' +
                    '</div>' +
                '</div>';

            $wrap.html(html);
        },

        loadProperties: function ($editor, schemaType) {
            var self = this;

            if (!schemaType) {
                $editor.find('.sg-property-rows').html('<tr class="sg-no-props"><td colspan="4"><em>Select a schema type to load properties.</em></td></tr>');
                return;
            }

            $editor.find('.sg-property-rows').html('<tr><td colspan="4">Loading properties&hellip;</td></tr>');

            // If we have no AJAX endpoint, go straight to the curated fallback.
            if (!this.ajaxUrl) {
                this.renderPropertyRows($editor, this.getPropertiesForType(schemaType));
                return;
            }

            $.post(this.ajaxUrl, {
                action:    'sg_get_type_properties',
                nonce:     this.nonce,
                type_slug: schemaType
            }, function (response) {
                if (response && response.success && response.data && response.data.properties && response.data.properties.length > 0) {
                    // Full vocabulary from the DB (includes inherited properties).
                    self.renderPropertyRows($editor, response.data.properties);
                } else {
                    // Dictionary not loaded yet — use the curated per-type set.
                    self.renderPropertyRows($editor, self.getPropertiesForType(schemaType));
                }
            }).fail(function () {
                self.renderPropertyRows($editor, self.getPropertiesForType(schemaType));
            });
        },

        /**
         * Instantly render a curated, pre-mapped property table for a type.
         * No server round-trip — works regardless of the dictionary state.
         */
        autoMapProperties: function ($editor, schemaType) {
            if (!schemaType) {
                $editor.find('.sg-property-rows').html('<tr class="sg-no-props"><td colspan="4"><em>Select a schema type to load properties.</em></td></tr>');
                return;
            }
            this.renderPropertyRows($editor, this.getPropertiesForType(schemaType));
        },

        renderPropertyRows: function ($editor, properties) {
            var $tbody = $editor.find('.sg-property-rows');
            $tbody.empty();

            var self = this;
            var seen = {};
            properties.forEach(function (prop) {
                var rawId  = prop.id || prop.schema_id || '';
                // Strip only the "schema:" / URL prefix — NOT a bare "schema"
                // (would mangle real props like "schemaVersion").
                var propId = String(rawId)
                    .replace(/^https?:\/\/schema\.org\//i, '')
                    .replace(/^schema:/i, '');
                var label  = prop.label || propId;
                var desc   = (prop.description || '').substring(0, 80);
                if (!propId || seen[propId]) { return; }
                seen[propId] = true;
                self.addPropertyRow($tbody, propId, label, desc);
            });
        },

        addPropertyRow: function ($container, propName, propLabel, propDesc) {
            var uuid = $container.closest('.sg-schema-editor').data('uuid') || '0';
            var name = 'sg_schemas[' + uuid + '][properties][' + propName + ']';

            // Auto-map well-known properties to a sensible WordPress source.
            var defaultSource = this.defaultSourceMap[propName] || '';

            var optionsHtml = '';
            this.sources.forEach(function (s) {
                var selected = ( s.value === defaultSource ) ? ' selected' : '';
                optionsHtml += '<option value="' + s.value + '"' + selected + '>' + s.label + '</option>';
            });

            var label = propLabel || propName;
            var desc  = propDesc ? ' <small style="color:#888;"> &mdash; ' + propDesc + '</small>' : '';

            var rowHtml =
                '<tr class="sg-property-row" data-prop="' + propName + '">' +
                    '<td><strong>' + label + '</strong>' + desc + '<br><small style="color:#666;">' + propName + '</small>' +
                    '<input type="hidden" name="' + name + '[name]" value="' + propName + '" />' +
                    '</td>' +
                    '<td><select class="sg-prop-source" name="' + name + '[source]">' + optionsHtml + '</select></td>' +
                    '<td>' +
                        '<input type="text" class="sg-prop-meta-key" name="' + name + '[meta_key]" placeholder="meta_key" style="display:none;width:100%;" />' +
                        '<input type="text" class="sg-prop-static" name="' + name + '[static_value]" placeholder="Static value" style="display:none;width:100%;" />' +
                    '</td>' +
                    '<td><button type="button" class="button-link sg-remove-property" style="color:#d63638;font-size:18px;">&times;</button></td>' +
                '</tr>';

            $container.find('.sg-no-props').closest('tr').remove();
            $container.append(rowHtml);
        },

        saveSchemas: function () {
            var self    = this;
            var $status = $('#sg-schemas-save-status');
            var schemas = [];

            $('#sg-schemas-list .sg-schema-card').each(function () {
                var $card    = $(this);
                var uuid     = $card.data('uuid') || '';
                var enabled  = $card.find('.sg-schema-enabled').is(':checked');
                var type     = ($card.find('.sg-schema-type-input').val() || $card.find('.sg-schema-type-search').val() || '')
                                   .replace(/^schema:/i, '').trim();

                if (!type) return;

                var properties = {};
                $card.find('.sg-property-row').each(function () {
                    var $row     = $(this);
                    var propName = $row.data('prop') || '';
                    var source   = $row.find('.sg-prop-source').val();

                    if (!propName) return;

                    if (source) {
                        properties[propName] = { source: source };
                        if (source === 'meta') {
                            properties[propName].meta_key = $row.find('.sg-prop-meta-key').val();
                        }
                        if (source === 'static') {
                            properties[propName].static_value = $row.find('.sg-prop-static').val();
                        }
                    }
                });

                schemas.push({
                    uuid:        uuid,
                    enabled:     enabled,
                    schema_type: type,
                    properties:  properties
                });
            });

            $status.text('Saving...').css('color', '#666');

            $.post(this.ajaxUrl, {
                action:  'sg_save_post_schemas',
                nonce:   this.nonce,
                post_id: this.postId,
                schemas: JSON.stringify(schemas)
            }, function (response) {
                if (response.success) {
                    $status.text(response.data.message || 'Saved!').css('color', '#00a32a');
                    setTimeout(function () { $status.text(''); }, 3000);
                } else {
                    $status.text(response.data.message || 'Failed.').css('color', '#d63638');
                }
            }).fail(function () {
                $status.text('Request failed.').css('color', '#d63638');
            });
        }
    };

    // Expose globally so the metabox can call it from inline scripts.
    window.SGMetabox = SGMetabox;

    // Try to init immediately (if DOM already has the metabox).
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            SGMetabox.init();
        });
    } else {
        SGMetabox.init();
    }

    // Also try again after a short delay (for block editor async loading).
    setTimeout(function () { SGMetabox.init(); }, 500);
    setTimeout(function () { SGMetabox.init(); }, 1500);

})(jQuery);
