class Patrol3DViewer {
    constructor(containerId) {
        this.container = document.getElementById(containerId);
        if (!this.container) throw new Error(`Container #${containerId} not found`);
        
        this.scene = null;
        this.camera = null;
        this.renderer = null;
        this.controls = null;
        this.nodes = [];
        this.pathLine = null;
        
        // Colors from App
        this.colors = {
            indigo: 0x6366f1, // Start
            green: 0x10b981,  // Scanned
            orange: 0xf59e0b, // Next / Pending
            grey: 0xe2e8f0,   // Default
            path: 0x3b82f6    // Line color
        };

        this.init();
    }

    init() {
        // Scene setup
        this.scene = new THREE.Scene();
        this.scene.background = new THREE.Color(0xf8fafc); // Light slate 50

        // Camera setup
        const width = this.container.clientWidth;
        const height = this.container.clientHeight;
        this.camera = new THREE.PerspectiveCamera(45, width / height, 0.1, 5000);
        this.camera.position.set(0, 500, 1000);

        // Renderer setup
        this.renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
        this.renderer.setSize(width, height);
        this.renderer.setPixelRatio(window.devicePixelRatio);
        this.container.appendChild(this.renderer.domElement);

        // Lighting
        const ambientLight = new THREE.AmbientLight(0xffffffff, 0.7);
        this.scene.add(ambientLight);

        const pointLight = new THREE.PointLight(0xffffff, 0.5);
        pointLight.position.set(500, 500, 500);
        this.scene.add(pointLight);

        // Orbit Controls
        this.controls = new THREE.OrbitControls(this.camera, this.renderer.domElement);
        this.controls.enableDamping = true;
        this.controls.dampingFactor = 0.05;

        // Grid Plane
        const grid = new THREE.GridHelper(2000, 50, 0xe2e8f0, 0xf1f5f9);
        this.scene.add(grid);

        window.addEventListener('resize', () => this.onWindowResize());
        this.animate();
    }

    onWindowResize() {
        if (!this.container) return;
        const width = this.container.clientWidth;
        const height = this.container.clientHeight;
        this.camera.aspect = width / height;
        this.camera.updateProjectionMatrix();
        this.renderer.setSize(width, height);
    }

    animate() {
        requestAnimationFrame(() => this.animate());
        if (this.controls) this.controls.update();
        if (this.renderer && this.scene && this.camera) {
            this.renderer.render(this.scene, this.camera);
        }
    }

    clear() {
        this.nodes.forEach(node => this.scene.remove(node));
        this.nodes = [];
        if (this.pathLine) {
            this.scene.remove(this.pathLine);
            this.pathLine = null;
        }
    }

    async renderTour(tourSessionId) {
        this.clear();
        
        try {
            const response = await fetch(`../gtapp/api/get_tour_visual_data.php?tour_session_id=${tourSessionId}`);
            const data = await response.json();
            
            if (data.status !== 'success' || !data.path || data.path.length === 0) {
                console.error('Failed to fetch tour data:', data.message);
                return;
            }

            const path = data.path;
            
            // Normalize Coordinates (Find Min and Range)
            const coords = path.map(p => ({ x: p.visual_pos_x, y: p.visual_pos_y }));
            const minX = Math.min(...coords.map(c => c.x));
            const maxX = Math.max(...coords.map(c => c.x));
            const minY = Math.min(...coords.map(c => c.y));
            const maxY = Math.max(...coords.map(c => c.y));
            
            const rangeX = maxX - minX || 1;
            const rangeY = maxY - minY || 1;
            
            // Map to 3D Space (Scale to ~1000 units)
            const scale = 1000 / Math.max(rangeX, rangeY);
            
            const points = [];
            
            path.forEach((p, index) => {
                const x = (p.visual_pos_x - minX - (maxX - minX) / 2) * scale;
                const z = (p.visual_pos_y - minY - (maxY - minY) / 2) * scale; // Y is Z in 3D horizontal plane
                const y = 5; // Slight elevation

                // Node Color
                let color = this.colors.grey;
                if (p.is_zero_checkpoint) color = this.colors.indigo;
                else if (p.status === 'on-time' || p.status === 'success') color = this.colors.green;
                else color = this.colors.orange;

                // Create Node (Cylinder/Disc)
                const geometry = new THREE.CylinderGeometry(15, 15, 10, 32);
                const material = new THREE.MeshPhongMaterial({ color: color });
                const node = new THREE.Mesh(geometry, material);
                node.position.set(x, y, z);
                this.scene.add(node);
                this.nodes.push(node);

                points.push(new THREE.Vector3(x, y, z));
            });

            // Create Path Line
            if (points.length > 1) {
                const curve = new THREE.CatmullRomCurve3(points);
                const tubeGeometry = new THREE.TubeGeometry(curve, 64, 4, 8, false);
                const tubeMaterial = new THREE.MeshPhongMaterial({ 
                    color: this.colors.path, 
                    transparent: true, 
                    opacity: 0.7 
                });
                this.pathLine = new THREE.Mesh(tubeGeometry, tubeMaterial);
                this.scene.add(this.pathLine);
            }
            
            // Focus Camera
            if (points.length > 0) {
                this.controls.reset();
            }

        } catch (error) {
            console.error('Error rendering 3D tour:', error);
        }
    }
}
