/**
 * Ecom360 Flow Builder – Visual Drag-Drop Canvas Engine
 * =====================================================
 * Pure vanilla JS + jQuery (already loaded). No external diagram libs needed.
 *
 * Architecture:
 *  - Nodes are HTML divs positioned absolutely inside .fb-canvas
 *  - Edges are SVG <path> Bézier curves rendered in an SVG layer
 *  - Left sidebar: palette of draggable node types
 *  - Right panel: properties editor for the selected node
 *  - Canvas supports pan (middle-click / shift-drag) and zoom (scroll)
 */
(function(window, $) {
    'use strict';

    /* ================================================================
       NODE TYPE DEFINITIONS
       ================================================================ */
    const NODE_TYPES = {
        // ── Triggers ──
        trigger: {
            label: 'Trigger',
            group: 'Triggers',
            icon: 'bx bx-play-circle',
            color: 'trigger',
            ports: { in: false, out: true },
            fields: [{
                    key: 'event_name',
                    label: 'Event Name',
                    type: 'select',
                    options: [
                        'cart_abandoned', 'purchase', 'signup', 'page_view', 'product_view',
                        'add_to_cart', 'remove_from_cart', 'checkout_start', 'wishlist_add',
                        'search', 'login', 'review_submitted'
                    ]
                },
                { key: 'filter', label: 'Filter Condition', type: 'text', placeholder: 'e.g. cart_value > 100' },
            ],
        },
        // ── Actions ──
        send_email: {
            label: 'Send Email',
            group: 'Actions',
            icon: 'bx bx-envelope',
            color: 'email',
            ports: { in: true, out: true },
            fields: [
                { key: 'template_id', label: 'Template', type: 'template-select' },
                { key: 'subject', label: 'Subject Line', type: 'text', placeholder: 'Email subject...' },
                { key: 'from_name', label: 'From Name', type: 'text', placeholder: 'Brand name' },
            ],
        },
        send_sms: {
            label: 'Send SMS',
            group: 'Actions',
            icon: 'bx bx-message-rounded-dots',
            color: 'sms',
            ports: { in: true, out: true },
            fields: [
                { key: 'template_id', label: 'Template', type: 'template-select' },
                { key: 'message', label: 'Message', type: 'textarea', placeholder: 'SMS text...' },
            ],
        },
        send_push: {
            label: 'Send Push',
            group: 'Actions',
            icon: 'bx bx-bell',
            color: 'push',
            ports: { in: true, out: true },
            fields: [
                { key: 'title', label: 'Title', type: 'text' },
                { key: 'body', label: 'Body', type: 'textarea' },
                { key: 'url', label: 'Click URL', type: 'text', placeholder: 'https://...' },
            ],
        },
        send_whatsapp: {
            label: 'Send WhatsApp',
            group: 'Actions',
            icon: 'bx bxl-whatsapp',
            color: 'whatsapp',
            ports: { in: true, out: true },
            fields: [
                { key: 'template_id', label: 'Template', type: 'template-select' },
            ],
        },
        // ── Logic ──
        delay: {
            label: 'Delay / Wait',
            group: 'Logic',
            icon: 'bx bx-time-five',
            color: 'delay',
            ports: { in: true, out: true },
            fields: [
                { key: 'duration', label: 'Duration', type: 'number', placeholder: '1' },
                { key: 'unit', label: 'Unit', type: 'select', options: ['minutes', 'hours', 'days', 'weeks'] },
            ],
        },
        condition: {
            label: 'If / Else',
            group: 'Logic',
            icon: 'bx bx-git-branch',
            color: 'condition',
            ports: { in: true, out: false, yes: true, no: true },
            fields: [
                { key: 'field', label: 'Check Field', type: 'text', placeholder: 'e.g. email_opened' },
                { key: 'operator', label: 'Operator', type: 'select', options: ['equals', 'not_equals', 'contains', 'greater_than', 'less_than', 'is_true', 'is_false'] },
                { key: 'value', label: 'Value', type: 'text' },
            ],
        },
        split: {
            label: 'A/B Split',
            group: 'Logic',
            icon: 'bx bx-git-compare',
            color: 'split',
            ports: { in: true, yes: true, no: true },
            fields: [
                { key: 'split_percent', label: 'Branch A %', type: 'number', placeholder: '50' },
            ],
        },
        // ── Integrations ──
        webhook: {
            label: 'Webhook',
            group: 'Integrations',
            icon: 'bx bx-link-external',
            color: 'webhook',
            ports: { in: true, out: true },
            fields: [
                { key: 'url', label: 'Webhook URL', type: 'text', placeholder: 'https://...' },
                { key: 'method', label: 'Method', type: 'select', options: ['POST', 'GET', 'PUT'] },
            ],
        },
        update_contact: {
            label: 'Update Contact',
            group: 'Integrations',
            icon: 'bx bx-user-check',
            color: 'update',
            ports: { in: true, out: true },
            fields: [
                { key: 'field', label: 'Field', type: 'text', placeholder: 'e.g. tags' },
                { key: 'action', label: 'Action', type: 'select', options: ['set', 'append', 'remove', 'increment'] },
                { key: 'value', label: 'Value', type: 'text' },
            ],
        },
        add_to_list: {
            label: 'Add to List',
            group: 'Integrations',
            icon: 'bx bx-list-plus',
            color: 'list',
            ports: { in: true, out: true },
            fields: [
                { key: 'list_id', label: 'List ID', type: 'text' },
            ],
        },
        remove_from_list: {
            label: 'Remove from List',
            group: 'Integrations',
            icon: 'bx bx-list-minus',
            color: 'list',
            ports: { in: true, out: true },
            fields: [
                { key: 'list_id', label: 'List ID', type: 'text' },
            ],
        },
        // ── End ──
        goal: {
            label: 'Goal',
            group: 'End',
            icon: 'bx bx-target-lock',
            color: 'goal',
            ports: { in: true, out: true },
            fields: [
                { key: 'event_name', label: 'Goal Event', type: 'text', placeholder: 'e.g. purchase' },
            ],
        },
        exit: {
            label: 'Exit',
            group: 'End',
            icon: 'bx bx-log-out',
            color: 'exit',
            ports: { in: true, out: false },
            fields: [],
        },
    };

    /* ================================================================
       FLOW BUILDER CLASS
       ================================================================ */
    class FlowBuilder {
        constructor(opts) {
            this.flowId = opts.flowId;
            this.apiBase = '/marketing/flows';
            this.nodes = new Map(); // nodeId → { id, type, position:{x,y}, config:{}, el }
            this.edges = []; // [{ id, from, to, fromPort, toPort, el }]
            this.selectedNodeId = null;
            this.selectedEdgeIdx = null;
            this.scale = 1;
            this.panX = 0;
            this.panY = 0;
            this.nodeCounter = 0;
            this.isDirty = false;
            this.templates = [];
            this.flowData = null;

            // DOM references
            this.$wrap = $('.fb-canvas-wrap');
            this.$canvas = $('.fb-canvas');
            this.$svg = this.$canvas.find('svg.fb-svg');
            this.$props = $('.fb-properties');

            this._initPalette();
            this._initCanvasEvents();
            this._initKeyboard();
            this._loadFlow();
            this._loadTemplates();
        }

        /* ────────────────────────────────────────────────────────────
           PALETTE – drag from sidebar onto canvas
           ──────────────────────────────────────────────────────────── */
        _initPalette() {
            const self = this;
            let ghost = null;
            let dragging = null;

            $(document).on('mousedown', '.fb-palette-item', function(e) {
                if (e.button !== 0) return;
                const type = $(this).data('type');
                const def = NODE_TYPES[type];
                if (!def) return;

                dragging = { type, startX: e.clientX, startY: e.clientY, started: false };
                e.preventDefault();
            });

            $(document).on('mousemove', function(e) {
                if (!dragging) return;
                if (!dragging.started) {
                    const dist = Math.abs(e.clientX - dragging.startX) + Math.abs(e.clientY - dragging.startY);
                    if (dist < 5) return;
                    dragging.started = true;
                    const def = NODE_TYPES[dragging.type];
                    ghost = $(`<div class="fb-drag-ghost fb-color-${def.color}">${def.label}</div>`).appendTo('body');
                }
                ghost.css({ left: e.clientX + 12, top: e.clientY + 6 });
            });

            $(document).on('mouseup', function(e) {
                if (!dragging || !dragging.started) { dragging = null; return; }
                if (ghost) ghost.remove();

                // Check if dropped on canvas
                const canvasRect = self.$wrap[0].getBoundingClientRect();
                if (e.clientX >= canvasRect.left && e.clientX <= canvasRect.right &&
                    e.clientY >= canvasRect.top && e.clientY <= canvasRect.bottom) {
                    const x = (e.clientX - canvasRect.left - self.panX) / self.scale;
                    const y = (e.clientY - canvasRect.top - self.panY) / self.scale;
                    self.addNode(dragging.type, { x, y });
                }
                dragging = null;
            });
        }

        /* ────────────────────────────────────────────────────────────
           CANVAS – pan, zoom, node drag, connection drawing
           ──────────────────────────────────────────────────────────── */
        _initCanvasEvents() {
            const self = this;

            // ── Zoom ──
            this.$wrap.on('wheel', function(e) {
                e.preventDefault();
                const delta = e.originalEvent.deltaY > 0 ? -0.05 : 0.05;
                self._zoom(delta, e.clientX, e.clientY);
            });

            // ── Pan (middle-click or shift+left) ──
            let panning = false,
                panStart = null;
            this.$wrap.on('mousedown', function(e) {
                if (e.button === 1 || (e.button === 0 && e.shiftKey)) {
                    panning = true;
                    panStart = { x: e.clientX - self.panX, y: e.clientY - self.panY };
                    self.$wrap.css('cursor', 'grabbing');
                    e.preventDefault();
                }
            });
            $(document).on('mousemove.pan', function(e) {
                if (!panning) return;
                self.panX = e.clientX - panStart.x;
                self.panY = e.clientY - panStart.y;
                self._applyTransform();
            });
            $(document).on('mouseup.pan', function() {
                if (panning) {
                    panning = false;
                    self.$wrap.css('cursor', '');
                }
            });

            // ── Click on canvas background → deselect ──
            this.$wrap.on('mousedown', function(e) {
                if (e.target === self.$wrap[0] || e.target === self.$canvas[0] || e.target.tagName === 'svg') {
                    if (!e.shiftKey && e.button === 0) {
                        self._deselectAll();
                    }
                }
            });

            // ── Node dragging ──
            let nodeDrag = null;
            this.$canvas.on('mousedown', '.fb-node-header', function(e) {
                if (e.button !== 0 || e.shiftKey) return;
                const $node = $(this).closest('.fb-node');
                const nodeId = $node.data('node-id');
                const node = self.nodes.get(nodeId);
                if (!node) return;

                self._selectNode(nodeId);
                nodeDrag = {
                    nodeId,
                    startX: e.clientX,
                    startY: e.clientY,
                    origX: node.position.x,
                    origY: node.position.y,
                    started: false,
                };
                e.preventDefault();
                e.stopPropagation();
            });

            $(document).on('mousemove.nodedrag', function(e) {
                if (!nodeDrag) return;
                if (!nodeDrag.started) {
                    const dist = Math.abs(e.clientX - nodeDrag.startX) + Math.abs(e.clientY - nodeDrag.startY);
                    if (dist < 3) return;
                    nodeDrag.started = true;
                    const node = self.nodes.get(nodeDrag.nodeId);
                    if (node) node.el.addClass('fb-node-dragging');
                }
                const dx = (e.clientX - nodeDrag.startX) / self.scale;
                const dy = (e.clientY - nodeDrag.startY) / self.scale;
                const node = self.nodes.get(nodeDrag.nodeId);
                if (!node) return;
                node.position.x = Math.round(nodeDrag.origX + dx);
                node.position.y = Math.round(nodeDrag.origY + dy);
                node.el.css({ left: node.position.x, top: node.position.y });
                self._renderEdges();
            });

            $(document).on('mouseup.nodedrag', function() {
                if (nodeDrag) {
                    const node = self.nodes.get(nodeDrag.nodeId);
                    if (node) node.el.removeClass('fb-node-dragging');
                    if (nodeDrag.started) self.isDirty = true;
                    nodeDrag = null;
                }
            });

            // ── Connection drawing (port → port) ──
            let connecting = null;
            this.$canvas.on('mousedown', '.fb-port', function(e) {
                if (e.button !== 0) return;
                e.stopPropagation();
                e.preventDefault();

                const $port = $(this);
                const $node = $port.closest('.fb-node');
                const nodeId = $node.data('node-id');
                const portType = $port.data('port'); // 'out', 'yes', 'no'

                if (['out', 'yes', 'no'].includes(portType)) {
                    connecting = { fromId: nodeId, fromPort: portType };
                    $port.addClass('fb-port-active');
                }
            });

            $(document).on('mousemove.connect', function(e) {
                if (!connecting) return;
                const fromNode = self.nodes.get(connecting.fromId);
                if (!fromNode) return;
                const rect = self.$wrap[0].getBoundingClientRect();
                const endX = (e.clientX - rect.left - self.panX) / self.scale;
                const endY = (e.clientY - rect.top - self.panY) / self.scale;
                const start = self._getPortPos(connecting.fromId, connecting.fromPort);
                self._renderTempLine(start.x, start.y, endX, endY);
            });

            $(document).on('mouseup.connect', function(e) {
                if (!connecting) return;
                self.$canvas.find('.fb-port-active').removeClass('fb-port-active');
                self._removeTempLine();

                // Check if released over an input port
                const el = document.elementFromPoint(e.clientX, e.clientY);
                const $target = $(el).closest('.fb-port[data-port="in"]');
                if ($target.length) {
                    const toId = $target.closest('.fb-node').data('node-id');
                    if (toId && toId !== connecting.fromId) {
                        self.addEdge(connecting.fromId, toId, connecting.fromPort, 'in');
                    }
                }
                connecting = null;
            });

            // ── Delete node button ──
            this.$canvas.on('click', '.fb-node-delete', function(e) {
                e.stopPropagation();
                const nodeId = $(this).closest('.fb-node').data('node-id');
                self.removeNode(nodeId);
            });
        }

        /* ────────────────────────────────────────────────────────────
           KEYBOARD SHORTCUTS
           ──────────────────────────────────────────────────────────── */
        _initKeyboard() {
            const self = this;
            $(document).on('keydown.fb', function(e) {
                // Delete / Backspace to remove selected node or edge
                if ((e.key === 'Delete' || e.key === 'Backspace') && !$(e.target).is('input,textarea,select')) {
                    if (self.selectedNodeId) {
                        self.removeNode(self.selectedNodeId);
                    } else if (self.selectedEdgeIdx !== null) {
                        self.removeEdge(self.selectedEdgeIdx);
                    }
                    e.preventDefault();
                }
                // Ctrl/Cmd + S to save
                if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                    e.preventDefault();
                    self.save();
                }
                // Escape to deselect
                if (e.key === 'Escape') {
                    self._deselectAll();
                }
            });
        }

        /* ────────────────────────────────────────────────────────────
           ZOOM / PAN
           ──────────────────────────────────────────────────────────── */
        _zoom(delta, cx, cy) {
            const oldScale = this.scale;
            this.scale = Math.max(0.2, Math.min(2, this.scale + delta));
            const rect = this.$wrap[0].getBoundingClientRect();
            const mx = cx - rect.left;
            const my = cy - rect.top;
            this.panX = mx - (mx - this.panX) * (this.scale / oldScale);
            this.panY = my - (my - this.panY) * (this.scale / oldScale);
            this._applyTransform();
            this._updateZoomLabel();
        }

        zoomIn() { this._zoom(0.1, this.$wrap.width() / 2, this.$wrap.height() / 2); }
        zoomOut() { this._zoom(-0.1, this.$wrap.width() / 2, this.$wrap.height() / 2); }
        zoomReset() {
            this.scale = 1;
            this.panX = 0;
            this.panY = 0;
            this._applyTransform();
            this._updateZoomLabel();
        }
        zoomFit() {
            if (this.nodes.size === 0) { this.zoomReset(); return; }
            let minX = Infinity,
                minY = Infinity,
                maxX = -Infinity,
                maxY = -Infinity;
            this.nodes.forEach(n => {
                minX = Math.min(minX, n.position.x);
                minY = Math.min(minY, n.position.y);
                maxX = Math.max(maxX, n.position.x + 200);
                maxY = Math.max(maxY, n.position.y + 80);
            });
            const pad = 60;
            const cw = this.$wrap.width();
            const ch = this.$wrap.height();
            const sx = (cw - pad * 2) / (maxX - minX || 1);
            const sy = (ch - pad * 2) / (maxY - minY || 1);
            this.scale = Math.max(0.2, Math.min(1.5, Math.min(sx, sy)));
            this.panX = pad - minX * this.scale;
            this.panY = pad - minY * this.scale;
            this._applyTransform();
            this._updateZoomLabel();
        }

        _applyTransform() {
            this.$canvas.css('transform', `translate(${this.panX}px, ${this.panY}px) scale(${this.scale})`);
        }
        _updateZoomLabel() {
            $('.fb-zoom-label').text(Math.round(this.scale * 100) + '%');
        }

        /* ────────────────────────────────────────────────────────────
           NODE MANAGEMENT
           ──────────────────────────────────────────────────────────── */
        addNode(type, position, config = {}, existingId = null) {
            const def = NODE_TYPES[type];
            if (!def) return null;

            const id = existingId || ('node_' + (++this.nodeCounter) + '_' + Date.now().toString(36));
            const node = {
                id,
                type,
                position: { x: Math.round(position.x), y: Math.round(position.y) },
                config: Object.assign({}, config),
                el: null,
            };

            // Build DOM
            const $el = this._buildNodeElement(node, def);
            this.$canvas.append($el);
            node.el = $el;
            this.nodes.set(id, node);
            this.isDirty = true;
            this._updateStatusBar();
            return id;
        }

        _buildNodeElement(node, def) {
            const $el = $(`
                <div class="fb-node" data-node-id="${node.id}" style="left:${node.position.x}px;top:${node.position.y}px">
                    <div class="fb-node-header fb-color-${def.color}">
                        <i class="fb-node-icon ${def.icon}"></i>
                        <span class="fb-node-type">${def.label}</span>
                        <i class="fb-node-delete bx bx-x"></i>
                    </div>
                    <div class="fb-node-body">
                        <div class="fb-node-label">${node.config._label || def.label}</div>
                        <div class="fb-node-desc">${this._getNodeSummary(node)}</div>
                    </div>
                </div>
            `);

            // Add ports
            if (def.ports.in) {
                $el.append('<div class="fb-port fb-port-in" data-port="in"></div>');
            }
            if (def.ports.out) {
                $el.append('<div class="fb-port fb-port-out" data-port="out"></div>');
            }
            if (def.ports.yes) {
                $el.append('<div class="fb-port fb-port-yes" data-port="yes" data-label="YES"></div>');
            }
            if (def.ports.no) {
                $el.append('<div class="fb-port fb-port-no" data-port="no" data-label="NO"></div>');
            }

            return $el;
        }

        _getNodeSummary(node) {
            const c = node.config;
            const t = node.type;
            if (t === 'trigger') return c.event_name ? `On: ${c.event_name}` : 'Configure trigger...';
            if (t === 'send_email') return c.subject || 'Configure email...';
            if (t === 'send_sms') return c.message ? c.message.substring(0, 30) + '...' : 'Configure SMS...';
            if (t === 'delay') return c.duration ? `Wait ${c.duration} ${c.unit || 'hours'}` : 'Set delay...';
            if (t === 'condition') return c.field ? `If ${c.field} ${c.operator || '='} ${c.value || ''}` : 'Set condition...';
            if (t === 'split') return c.split_percent ? `${c.split_percent}% / ${100 - c.split_percent}%` : '50/50 split';
            if (t === 'webhook') return c.url ? c.url.substring(0, 30) : 'Set webhook URL...';
            if (t === 'goal') return c.event_name || 'Set goal event...';
            return 'Click to configure';
        }

        _refreshNodeDOM(nodeId) {
            const node = this.nodes.get(nodeId);
            if (!node) return;
            const def = NODE_TYPES[node.type];
            node.el.find('.fb-node-label').text(node.config._label || def.label);
            node.el.find('.fb-node-desc').text(this._getNodeSummary(node));
        }

        removeNode(nodeId) {
            const node = this.nodes.get(nodeId);
            if (!node) return;
            node.el.remove();
            this.nodes.delete(nodeId);
            // Remove connected edges
            this.edges = this.edges.filter(e => e.from !== nodeId && e.to !== nodeId);
            if (this.selectedNodeId === nodeId) {
                this.selectedNodeId = null;
                this._renderProperties(null);
            }
            this._renderEdges();
            this.isDirty = true;
            this._updateStatusBar();
        }

        /* ────────────────────────────────────────────────────────────
           EDGE MANAGEMENT
           ──────────────────────────────────────────────────────────── */
        addEdge(fromId, toId, fromPort = 'out', toPort = 'in') {
            // Prevent duplicate
            const exists = this.edges.some(e => e.from === fromId && e.to === toId && e.fromPort === fromPort);
            if (exists) return;
            // Prevent self-loop
            if (fromId === toId) return;

            const edge = {
                id: 'edge_' + Date.now().toString(36),
                from: fromId,
                to: toId,
                fromPort,
                toPort,
            };
            this.edges.push(edge);
            this._renderEdges();
            this.isDirty = true;
            this._updateStatusBar();
        }

        removeEdge(idx) {
            if (idx < 0 || idx >= this.edges.length) return;
            this.edges.splice(idx, 1);
            this.selectedEdgeIdx = null;
            this._renderEdges();
            this.isDirty = true;
            this._updateStatusBar();
        }

        _getPortPos(nodeId, portType) {
            const node = this.nodes.get(nodeId);
            if (!node) return { x: 0, y: 0 };
            const el = node.el[0];
            const w = el.offsetWidth;
            const h = el.offsetHeight;
            const x = node.position.x;
            const y = node.position.y;

            switch (portType) {
                case 'in':
                    return { x: x + w / 2, y: y };
                case 'out':
                    return { x: x + w / 2, y: y + h };
                case 'yes':
                    return { x: x + w * 0.3, y: y + h };
                case 'no':
                    return { x: x + w * 0.7, y: y + h };
                default:
                    return { x: x + w / 2, y: y + h };
            }
        }

        _renderEdges() {
            const self = this;
            const svg = this.$svg[0];
            // Clear existing
            while (svg.firstChild) svg.removeChild(svg.firstChild);

            // Render a defs for arrow markers
            const defs = document.createElementNS('http://www.w3.org/2000/svg', 'defs');
            defs.innerHTML = `
                <marker id="fb-arrow" markerWidth="8" markerHeight="8" refX="7" refY="4" orient="auto">
                    <path d="M0,0 L8,4 L0,8 Z" fill="#adb5bd"/>
                </marker>
                <marker id="fb-arrow-sel" markerWidth="8" markerHeight="8" refX="7" refY="4" orient="auto">
                    <path d="M0,0 L8,4 L0,8 Z" fill="#556ee6"/>
                </marker>
            `;
            svg.appendChild(defs);

            this.edges.forEach((edge, idx) => {
                const start = this._getPortPos(edge.from, edge.fromPort);
                const end = this._getPortPos(edge.to, edge.toPort);
                if (!start || !end) return;

                const isSelected = (self.selectedEdgeIdx === idx);
                const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                const d = this._bezierPath(start.x, start.y, end.x, end.y);
                path.setAttribute('d', d);
                path.setAttribute('fill', 'none');
                path.setAttribute('stroke', isSelected ? '#556ee6' : '#adb5bd');
                path.setAttribute('stroke-width', isSelected ? '3' : '2');
                path.setAttribute('marker-end', isSelected ? 'url(#fb-arrow-sel)' : 'url(#fb-arrow)');
                if (isSelected) path.classList.add('fb-edge-selected');
                path.style.pointerEvents = 'stroke';
                path.style.cursor = 'pointer';
                path.dataset.edgeIdx = idx;

                path.addEventListener('click', (e) => {
                    e.stopPropagation();
                    self._selectEdge(idx);
                });

                svg.appendChild(path);

                // Label for condition branches
                if (edge.fromPort === 'yes' || edge.fromPort === 'no') {
                    const mx = (start.x + end.x) / 2;
                    const my = (start.y + end.y) / 2 - 8;
                    const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
                    text.setAttribute('x', mx);
                    text.setAttribute('y', my);
                    text.setAttribute('text-anchor', 'middle');
                    text.setAttribute('font-size', '10');
                    text.setAttribute('font-weight', '700');
                    text.setAttribute('fill', edge.fromPort === 'yes' ? '#34c38f' : '#f46a6a');
                    text.classList.add('fb-edge-label');
                    text.textContent = edge.fromPort.toUpperCase();
                    svg.appendChild(text);
                }
            });
        }

        _bezierPath(x1, y1, x2, y2) {
            const dy = Math.abs(y2 - y1);
            const cp = Math.max(50, dy * 0.5);
            return `M${x1},${y1} C${x1},${y1 + cp} ${x2},${y2 - cp} ${x2},${y2}`;
        }

        _renderTempLine(x1, y1, x2, y2) {
            let $temp = this.$svg.find('.fb-temp-line');
            if (!$temp.length) {
                const line = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                line.classList.add('fb-temp-line');
                line.setAttribute('fill', 'none');
                line.setAttribute('stroke', '#556ee6');
                line.setAttribute('stroke-width', '2');
                line.setAttribute('stroke-dasharray', '6,4');
                this.$svg[0].appendChild(line);
                $temp = $(line);
            }
            $temp[0].setAttribute('d', this._bezierPath(x1, y1, x2, y2));
        }

        _removeTempLine() {
            this.$svg.find('.fb-temp-line').remove();
        }

        /* ────────────────────────────────────────────────────────────
           SELECTION
           ──────────────────────────────────────────────────────────── */
        _selectNode(nodeId) {
            this._deselectAll();
            this.selectedNodeId = nodeId;
            const node = this.nodes.get(nodeId);
            if (node) node.el.addClass('fb-node-selected');
            this._renderProperties(nodeId);
        }

        _selectEdge(idx) {
            this._deselectAll();
            this.selectedEdgeIdx = idx;
            this._renderEdges();
            this._renderEdgeProperties(idx);
        }

        _deselectAll() {
            if (this.selectedNodeId) {
                const n = this.nodes.get(this.selectedNodeId);
                if (n) n.el.removeClass('fb-node-selected');
            }
            this.selectedNodeId = null;
            this.selectedEdgeIdx = null;
            this._renderEdges();
            this._renderProperties(null);
        }

        /* ────────────────────────────────────────────────────────────
           PROPERTIES PANEL
           ──────────────────────────────────────────────────────────── */
        _renderProperties(nodeId) {
            const $body = this.$props.find('.fb-properties-body');
            const $header = this.$props.find('.fb-properties-header span');

            if (!nodeId) {
                $header.text('Properties');
                $body.html(`
                    <div class="fb-empty-state">
                        <i class="bx bx-pointer"></i>
                        <p>Select a node to edit<br>its properties</p>
                    </div>
                `);
                return;
            }

            const node = this.nodes.get(nodeId);
            if (!node) return;
            const def = NODE_TYPES[node.type];
            $header.text(def.label + ' Properties');

            let html = '';

            // Label field (always)
            html += `
                <div class="mb-3">
                    <label class="form-label">Label</label>
                    <input type="text" class="form-control form-control-sm fb-prop-input" data-key="_label"
                           value="${this._esc(node.config._label || '')}" placeholder="${def.label}">
                </div>
            `;

            // Type-specific fields
            (def.fields || []).forEach(f => {
                const val = node.config[f.key] || '';
                html += `<div class="mb-3"><label class="form-label">${f.label}</label>`;

                if (f.type === 'select') {
                    html += `<select class="form-select form-select-sm fb-prop-input" data-key="${f.key}">`;
                    html += `<option value="">-- Select --</option>`;
                    (f.options || []).forEach(o => {
                        html += `<option value="${o}" ${val === o ? 'selected' : ''}>${o.replace(/_/g, ' ')}</option>`;
                    });
                    html += `</select>`;
                } else if (f.type === 'textarea') {
                    html += `<textarea class="form-control form-control-sm fb-prop-input" data-key="${f.key}" rows="3"
                              placeholder="${f.placeholder || ''}">${this._esc(val)}</textarea>`;
                } else if (f.type === 'template-select') {
                    html += `<select class="form-select form-select-sm fb-prop-input" data-key="${f.key}">`;
                    html += `<option value="">-- Select Template --</option>`;
                    this.templates.forEach(t => {
                        html += `<option value="${t.id}" ${val == t.id ? 'selected' : ''}>${this._esc(t.name)}</option>`;
                    });
                    html += `</select>`;
                } else if (f.type === 'number') {
                    html += `<input type="number" class="form-control form-control-sm fb-prop-input" data-key="${f.key}"
                              value="${this._esc(val)}" placeholder="${f.placeholder || ''}">`;
                } else {
                    html += `<input type="text" class="form-control form-control-sm fb-prop-input" data-key="${f.key}"
                              value="${this._esc(val)}" placeholder="${f.placeholder || ''}">`;
                }
                html += `</div>`;
            });

            // Node ID (readonly info)
            html += `<div class="mt-3 pt-3 border-top"><small class="text-muted">Node ID: ${node.id}</small></div>`;

            $body.html(html);

            // Bind change events
            const self = this;
            $body.find('.fb-prop-input').on('input change', function() {
                const key = $(this).data('key');
                node.config[key] = $(this).val();
                self._refreshNodeDOM(nodeId);
                self.isDirty = true;
            });
        }

        _renderEdgeProperties(idx) {
            const edge = this.edges[idx];
            if (!edge) return;
            const $body = this.$props.find('.fb-properties-body');
            const $header = this.$props.find('.fb-properties-header span');
            $header.text('Edge Properties');

            const fromNode = this.nodes.get(edge.from);
            const toNode = this.nodes.get(edge.to);

            $body.html(`
                <div class="mb-3">
                    <label class="form-label">From</label>
                    <input type="text" class="form-control form-control-sm" value="${fromNode ? fromNode.config._label || NODE_TYPES[fromNode.type]?.label : edge.from}" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label">To</label>
                    <input type="text" class="form-control form-control-sm" value="${toNode ? toNode.config._label || NODE_TYPES[toNode.type]?.label : edge.to}" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label">Port</label>
                    <input type="text" class="form-control form-control-sm" value="${edge.fromPort} → ${edge.toPort}" readonly>
                </div>
                <div class="mt-3">
                    <button class="btn btn-danger btn-sm w-100 fb-delete-edge" data-idx="${idx}">
                        <i class="bx bx-trash me-1"></i>Delete Connection
                    </button>
                </div>
            `);

            const self = this;
            $body.find('.fb-delete-edge').on('click', function() {
                self.removeEdge(parseInt($(this).data('idx')));
            });
        }

        /* ────────────────────────────────────────────────────────────
           API – LOAD / SAVE
           ──────────────────────────────────────────────────────────── */
        async _loadFlow() {
            try {
                const res = await EcomAPI.get(`${this.apiBase}/${this.flowId}`);
                this.flowData = res.data;

                // Update header
                $('#fb-flow-name').text(this.flowData.name || 'Untitled Flow');
                $('#fb-flow-status').html(EcomUtils.statusBadge(this.flowData.status || 'draft'));

                // Load nodes from the related nodes/edges
                const nodes = this.flowData.nodes || [];
                const edges = this.flowData.edges || [];

                if (nodes.length > 0) {
                    nodes.forEach(n => {
                        this.addNode(n.type, n.position || { x: 100, y: 100 }, n.config || {}, n.node_id);
                    });
                    edges.forEach(e => {
                        this.addEdge(e.source_node_id, e.target_node_id, e.label || 'out', 'in');
                    });
                } else if (this.flowData.canvas && this.flowData.canvas.nodes) {
                    // Fallback: load from canvas JSON
                    (this.flowData.canvas.nodes || []).forEach(n => {
                        this.addNode(n.type, n.position || { x: 100, y: 100 }, n.config || {}, n.id);
                    });
                    (this.flowData.canvas.edges || []).forEach(e => {
                        this.addEdge(e.from, e.to, e.fromPort || 'out', e.toPort || 'in');
                    });
                }

                this.isDirty = false;
                this._updateStatusBar();
                if (this.nodes.size > 0) this.zoomFit();
            } catch (err) {
                toastr.error('Failed to load flow: ' + (err.message || 'Unknown error'));
            }
        }

        async _loadTemplates() {
            try {
                const res = await EcomAPI.get('/marketing/templates?per_page=100');
                const d = res.data;
                this.templates = (d && d.data) ? d.data : (d || []);
            } catch (e) {
                // Silent
            }
        }

        async save() {
            const nodesArr = [];
            const edgesArr = [];

            this.nodes.forEach((node) => {
                nodesArr.push({
                    node_id: node.id,
                    type: node.type,
                    config: node.config,
                    position: node.position,
                });
            });

            this.edges.forEach((edge) => {
                edgesArr.push({
                    source_node_id: edge.from,
                    target_node_id: edge.to,
                    label: edge.fromPort !== 'out' ? edge.fromPort : null,
                });
            });

            try {
                const $btn = $('#fb-save-btn');
                $btn.prop('disabled', true).html('<i class="bx bx-loader-alt bx-spin me-1"></i>Saving...');

                await EcomAPI.put(`${this.apiBase}/${this.flowId}/canvas`, {
                    nodes: nodesArr,
                    edges: edgesArr,
                    canvas: {
                        nodes: nodesArr.map(n => ({ id: n.node_id, type: n.type, position: n.position, config: n.config })),
                        edges: this.edges.map(e => ({ from: e.from, to: e.to, fromPort: e.fromPort, toPort: e.toPort })),
                    },
                });

                this.isDirty = false;
                toastr.success('Flow saved successfully!');
                this._updateStatusBar();
                $btn.prop('disabled', false).html('<i class="bx bx-save me-1"></i>Save');
            } catch (err) {
                toastr.error('Save failed: ' + (err.message || 'Unknown error'));
                $('#fb-save-btn').prop('disabled', false).html('<i class="bx bx-save me-1"></i>Save');
            }
        }

        async activate() {
            if (this.nodes.size === 0) {
                toastr.warning('Add at least one node before activating.');
                return;
            }
            if (this.isDirty) {
                await this.save();
            }
            try {
                await EcomAPI.post(`${this.apiBase}/${this.flowId}/activate`);
                this.flowData.status = 'active';
                $('#fb-flow-status').html(EcomUtils.statusBadge('active'));
                toastr.success('Flow activated!');
            } catch (err) {
                toastr.error('Activation failed: ' + (err.message || 'Unknown error'));
            }
        }

        async pause() {
            try {
                await EcomAPI.post(`${this.apiBase}/${this.flowId}/pause`);
                this.flowData.status = 'paused';
                $('#fb-flow-status').html(EcomUtils.statusBadge('paused'));
                toastr.success('Flow paused.');
            } catch (err) {
                toastr.error('Pause failed: ' + (err.message || 'Unknown error'));
            }
        }

        /* ────────────────────────────────────────────────────────────
           STATUS BAR
           ──────────────────────────────────────────────────────────── */
        _updateStatusBar() {
            $('#fb-stat-nodes').text(this.nodes.size + ' nodes');
            $('#fb-stat-edges').text(this.edges.length + ' connections');
            $('#fb-stat-dirty').text(this.isDirty ? '● Unsaved changes' : '✓ Saved');
            $('#fb-stat-dirty').css('color', this.isDirty ? '#f1b44c' : '#34c38f');
        }

        /* ────────────────────────────────────────────────────────────
           UTILITIES
           ──────────────────────────────────────────────────────────── */
        _esc(str) {
            if (!str) return '';
            return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        destroy() {
            $(document).off('.fb').off('.pan').off('.nodedrag').off('.connect');
        }
    }

    /* ── Expose ─────────────────────────────────────────────── */
    window.FlowBuilder = FlowBuilder;
    window.FB_NODE_TYPES = NODE_TYPES;

})(window, jQuery);