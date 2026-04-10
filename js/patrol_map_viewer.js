class PatrolMapViewer {
    constructor(containerId) {
        console.log('Initializing PatrolMapViewer for container:', containerId);
        this.container = document.getElementById(containerId);
        if (!this.container) {
            console.error('Container not found:', containerId);
            return;
        }
        
        this.container.innerHTML = ''; 
        this.canvas = document.createElement('canvas');
        this.canvas.style.display = 'block';
        this.canvas.style.width = '100%';
        this.canvas.style.height = '100%';
        
        this.ctx = this.canvas.getContext('2d');
        this.container.appendChild(this.canvas);
        
        this.pathData = [];
        this.padding = 60;
        this.isLoading = false;
        
        window.addEventListener('resize', () => this.resize());
        
        // Initial setup
        setTimeout(() => this.resize(), 100); 
    }

    resize() {
        // Force width calculation from parent container
        const rect = this.container.getBoundingClientRect();
        if (rect.width > 0 && rect.height > 0) {
            this.canvas.width = rect.width;
            this.canvas.height = rect.height;
        } else {
            // Fallback for hidden modals
            this.canvas.width = this.container.clientWidth || 800;
            this.canvas.height = this.container.clientHeight || 500;
        }
        
        if (this.isLoading) {
            this.drawMessage('Loading Patrol Data...', '#f8fafc', '#64748b');
        } else if (this.pathData && this.pathData.length > 0) {
            this.draw();
        } else if (!this.isLoading) {
            this.drawMessage('No checkpoint data available for this site configuration.', '#f9fafb', '#94a3b8');
        }
    }

    async renderTour(tourId, mappingId = '') {
        console.log('Requesting tour data:', { tourId, mappingId });
        this.isLoading = true;
        this.resize(); 
        this.drawMessage('Loading Patrol Data...', '#f8fafc', '#64748b');
        
        try {
            const url = `api/get_tour_visual_data.php?tour_session_id=${encodeURIComponent(tourId)}&mapping_id=${mappingId}&v=${Date.now()}`;
            const response = await fetch(url);
            
            if (!response.ok) throw new Error('Network response was not ok');
            
            const data = await response.json();
            console.log('Received data:', data);
            
            this.isLoading = false;
            if (data.status === 'success') {
                this.pathData = data.path;
                this.draw();
            } else {
                this.drawMessage(data.message || 'Error loading tour data', '#fef2f2', '#ef4444');
            }
        } catch (error) {
            console.error('Loader Error:', error);
            this.isLoading = false;
            this.drawMessage('Communication Error: Please check your internet connection or server logs.', '#fff7ed', '#ea580c');
        }
    }

    drawMessage(msg, bgColor, textColor) {
        this.ctx.fillStyle = bgColor;
        this.ctx.fillRect(0, 0, this.canvas.width, this.canvas.height);
        
        this.ctx.fillStyle = textColor;
        this.ctx.font = '600 18px Inter, sans-serif';
        this.ctx.textAlign = 'center';
        this.ctx.fillText(msg, this.canvas.width/2, this.canvas.height/2);
    }

    draw() {
        const cw = this.canvas.width;
        const ch = this.canvas.height;
        this.ctx.clearRect(0, 0, cw, ch);
        
        if (!this.pathData || this.pathData.length === 0) {
            this.drawMessage('No checkpoint data available for this site configuration.', '#f9fafb', '#94a3b8');
            return;
        }

        // Calculate bounds
        let minX = Infinity, maxX = -Infinity, minY = Infinity, maxY = -Infinity;
        this.pathData.forEach(p => {
            const px = parseFloat(p.visual_pos_x) || 0;
            const py = parseFloat(p.visual_pos_y) || 0;
            minX = Math.min(minX, px);
            maxX = Math.max(maxX, px);
            minY = Math.min(minY, py);
            maxY = Math.max(maxY, py);
        });

        if (minX === maxX) { minX -= 200; maxX += 200; }
        if (minY === maxY) { minY -= 200; maxY += 200; }

        const dataWidth = maxX - minX;
        const dataHeight = maxY - minY;
        const scaleX = (cw - this.padding * 2) / dataWidth;
        const scaleY = (ch - this.padding * 2) / dataHeight;
        const scale = Math.min(scaleX, scaleY);
        const offsetX = (cw - dataWidth * scale) / 2 - minX * scale;
        const offsetY = (ch - dataHeight * scale) / 2 - minY * scale;

        const project = (x, y) => ({
            x: (parseFloat(x) || 0) * scale + offsetX,
            y: (parseFloat(y) || 0) * scale + offsetY
        });

        // Helper: determine visual state from status string
        const getState = (status) => {
            const s = (status || '').toLowerCase().trim();
            if (s === 'missed' || s === 'late') return 'missed'; // Both missed + justified = red
            if (s !== '') return 'scanned';                       // Any other non-empty = scanned (green)
            return 'pending';                                     // No scan record (grey)
        };

        const segmentColor = {
            scanned: '#60a5fa', // Blue
            missed:  '#ef4444', // Red (covers both missed and justified)
            pending: '#cbd5e1', // Grey
        };

        // Build Sequence & Close Loop
        const startIdx = this.pathData.findIndex(p => p.is_zero_checkpoint);
        const drawSequence = [...this.pathData];
        const startPoint = startIdx >= 0 ? this.pathData[startIdx] : this.pathData[0];
        const lastPoint = drawSequence[drawSequence.length - 1];
        if (lastPoint.checkpoint_id !== startPoint.checkpoint_id && drawSequence.length > 1) {
            drawSequence.push(startPoint); 
        }

        // 1. Draw Paths
        for (let i = 0; i < drawSequence.length - 1; i++) {
            const from = drawSequence[i];
            const to   = drawSequence[i + 1];
            const p1 = project(from.visual_pos_x, from.visual_pos_y);
            const p2 = project(to.visual_pos_x, to.visual_pos_y);
            
            const toState = getState(to.status);
            const color = segmentColor[toState];
            const isDashed = (toState === 'pending');

            this.ctx.beginPath();
            this.ctx.strokeStyle = color;
            this.ctx.lineWidth = 4;
            this.ctx.lineCap = 'round';
            this.ctx.setLineDash(isDashed ? [8, 8] : []);
            this.ctx.moveTo(p1.x, p1.y);
            this.ctx.lineTo(p2.x, p2.y);
            this.ctx.stroke();
            
            if (!isDashed) {
                this.drawArrow(p1.x, p1.y, p2.x, p2.y, color);
            }
        }
        this.ctx.setLineDash([]);

        // 2. Draw Nodes
        this.pathData.forEach(p => {
            const pos = project(p.visual_pos_x, p.visual_pos_y);
            const state = getState(p.status);
            
            if (state === 'scanned') {
                this.drawScannedNode(pos.x, pos.y, p.checkpoint_name);
            } else if (state === 'missed') {
                this.drawMissedNode(pos.x, pos.y, p.checkpoint_name);
            } else {
                this.drawPlaceholderNode(pos.x, pos.y, p.checkpoint_name);
            }
        });
    }

    drawArrow(x1, y1, x2, y2, color = '#60a5fa') {
        const midX = (x1 + x2) / 2;
        const midY = (y1 + y2) / 2;
        const angle = Math.atan2(y2 - y1, x2 - x1);
        const size = 12;
        this.ctx.fillStyle = color;
        this.ctx.beginPath();
        this.ctx.moveTo(midX, midY);
        this.ctx.lineTo(midX - size * Math.cos(angle - Math.PI / 6), midY - size * Math.sin(angle - Math.PI / 6));
        this.ctx.lineTo(midX - size * Math.cos(angle + Math.PI / 6), midY - size * Math.sin(angle + Math.PI / 6));
        this.ctx.closePath();
        this.ctx.fill();
    }

    drawScannedNode(x, y, label) {
        this.ctx.fillStyle = '#10b981';
        this.ctx.beginPath(); this.ctx.arc(x, y, 16, 0, Math.PI * 2); this.ctx.fill();
        this.ctx.strokeStyle = 'white'; this.ctx.lineWidth = 3; this.ctx.beginPath();
        this.ctx.moveTo(x - 6, y); this.ctx.lineTo(x - 2, y + 5); this.ctx.lineTo(x + 7, y - 6);
        this.ctx.stroke();
        this.drawLabel(x, y, label);
    }

    drawMissedNode(x, y, label) {
        this.ctx.fillStyle = '#ef4444';
        this.ctx.beginPath(); this.ctx.arc(x, y, 16, 0, Math.PI * 2); this.ctx.fill();
        this.ctx.strokeStyle = 'white'; this.ctx.lineWidth = 3; const s = 6;
        this.ctx.beginPath(); this.ctx.moveTo(x - s, y - s); this.ctx.lineTo(x + s, y + s);
        this.ctx.moveTo(x + s, y - s); this.ctx.lineTo(x - s, y + s);
        this.ctx.stroke();
        this.drawLabel(x, y, label);
    }

    drawPlaceholderNode(x, y, label) {
        this.ctx.fillStyle = '#e2e8f0';
        this.ctx.beginPath(); this.ctx.arc(x, y, 10, 0, Math.PI * 2); this.ctx.fill();
        this.ctx.strokeStyle = '#94a3b8'; this.ctx.lineWidth = 2; this.ctx.stroke();
        this.drawLabel(x, y, label);
    }

    drawLabel(x, y, text) {
        this.ctx.fillStyle = '#475569';
        this.ctx.font = '700 12px Inter, sans-serif';
        this.ctx.textAlign = 'center';
        this.ctx.fillText(text, x, y + 34);
    }
}
