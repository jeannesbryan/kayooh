<?php
// record_single.php - Kayooh v5.0 (Ultimate Solo Mode - Fixed Anchor & Auto-Finish)
session_start();
$db_file = __DIR__ . '/kayooh.sqlite';

if (!isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$room_id = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['room'] ?? 'SINGLE_MODE');

try {
    $pdo = new PDO("sqlite:" . $db_file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('telegram_bot_token', 'telegram_chat_id', 'captain_name')");
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) { die("Database error: " . $e->getMessage()); }
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Kayooh Solo - <?= $room_id ?></title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        :root { --primary: #e67e22; --dark: #1a1a1a; --card: #2c3e50; }
        
        * { box-sizing: border-box; }
        
        body { font-family: 'Segoe UI', sans-serif; background: var(--dark); color: white; margin: 0; overflow: hidden; }
        #map { height: 100vh; width: 100%; z-index: 1; }
        
        .overlay-ui { position: absolute; left: 0; z-index: 1000; width: 100%; pointer-events: none; }
        .top-bar { top: 0; padding: 15px; display: flex; justify-content: space-between; align-items: flex-start; }
        .bottom-bar { bottom: 0; padding: 20px; background: linear-gradient(transparent, rgba(0,0,0,0.8)); }
        
        .stat-card { background: var(--card); padding: 15px; border-radius: 12px; pointer-events: auto; box-shadow: 0 4px 15px rgba(0,0,0,0.5); border-left: 4px solid var(--primary); }
        .grid-stats { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 15px; }
        
        .btn { padding: 12px; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; pointer-events: auto; text-transform: uppercase; }
        .btn-stop { background: #c0392b; color: white; width: 100%; }
        .btn-copy { background: #34495e; color: #ecf0f1; font-size: 11px; display: block; width: 100%; margin-bottom: 5px;}
        
        .label { font-size: 10px; color: #bdc3c7; margin-bottom: 2px; }
        .val { font-size: 18px; font-weight: bold; color: #f1c40f; }

        #uploadOverlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.9); z-index: 9999; display: none; flex-direction: column; justify-content: center; align-items: center; text-align: center; }
        .progress-bar { width: 80%; background: #444; height: 10px; border-radius: 5px; margin-top: 20px; overflow: hidden; }
        #progressFill { width: 0%; height: 100%; background: var(--primary); transition: width 0.3s; }
    
        /* --- STEALTH MODE v8.0 --- */
        #stealthOverlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: #000000; z-index: 99999; display: none; flex-direction: column; justify-content: center; align-items: center; color: #333; font-family: sans-serif; user-select: none; -webkit-user-select: none; }
        /* --- STEALTH MODE & JUKEBOX BUTTONS --- */
        .btn-stealth { background: #2c3e50; color: white; border: none; padding: 12px; border-radius: 10px; font-weight: bold; cursor: pointer; font-size: 12px; display: flex; align-items: center; justify-content: center; gap: 5px; box-shadow: 0 4px 6px rgba(0,0,0,0.3); pointer-events: auto; flex: 1; }
        .action-row { display: flex; gap: 10px; margin-bottom: 10px; width: 100%; }
    </style>
</head>
<body>

    <div id="stealthOverlay" ondblclick="disableStealth()">
        <div style="font-size: 60px; filter: grayscale(1); opacity: 0.1; margin-bottom: 20px;">🚲</div>
        <div style="font-size: 10px; letter-spacing: 2px; opacity: 0.3;">STEALTH MODE ACTIVE</div>
        <div style="font-size: 9px; margin-top: 10px; opacity: 0.2;">Ketuk 2x untuk membuka</div>
    </div>

    <div id="uploadOverlay">
        <h2 id="statusTitle">🚀 MENGIRIM DATA...</h2>
        <p id="statusMsg">Memotong rute menjadi bagian kecil agar satelit R2 aman.</p>
        <div class="progress-bar"><div id="progressFill"></div></div>
    </div>

    <div class="overlay-ui top-bar">
        <div class="stat-card" style="padding: 8px 15px;">
            <div class="label">SINGLE MODE</div>
            <div style="font-size: 12px; color: #2ecc71;">● LIVE BROADCASTING</div>
        </div>
        <div style="pointer-events: auto;">
            <button class="btn btn-copy" onclick="copyTrackingLink()">📋 COPY LINK</button>
            <button class="btn btn-copy" onclick="cancelRide()" style="background: #e74c3c; color: white;">🛑 BATALKAN</button>
        </div>
    </div>

    <div id="map"></div>

    <div class="overlay-ui bottom-bar">
        <div id="gps-info" style="text-align: center; font-size: 12px; font-weight: bold; margin-bottom: 15px; color: #bdc3c7; background: rgba(0,0,0,0.5); padding: 5px; border-radius: 5px; pointer-events: auto;">
            🟢 SIAP MEREKAM (KLIK START)
        </div>
        <div class="grid-stats">
            <div class="stat-card">
                <div class="label">JARAK (KM)</div>
                <div class="val" id="distVal">0.00</div>
            </div>
            <div class="stat-card">
                <div class="label">SPEED (KM/H)</div>
                <div class="val" id="speedVal">0.0</div>
            </div>
            <div class="stat-card">
                <div class="label">ELEVASI (M)</div>
                <div class="val" id="elevVal">0</div>
            </div>
            <div class="stat-card">
                <div class="label">MOVING TIME</div>
                <div class="val" id="timeVal">00:00:00</div>
            </div>
        </div>
        <div class="action-row">
            <button id="btnStealth" class="btn-stealth" onclick="enableStealth()" style="display:none;">🔒 STEALTH</button>
            <button id="btnMusic" class="btn-stealth" onclick="document.getElementById('musicInput').click()" style="background:#8e44ad;">🎵 MUSIK</button>
            <button id="btnSkip" class="btn-stealth" onclick="audioPlayer.currentTime = audioPlayer.duration;" style="background:#d35400;">⏭️ SKIP</button>
        </div>

        <button class="btn btn-stop" id="btnStart" onclick="startRide()" style="background: #2ecc71;">▶️ MULAI GOWES</button>
        <button class="btn btn-stop" id="btnStop" onclick="finishRide()" style="display: none;">⬜ SELESAI GOWES</button>
        
        <input type="file" id="musicInput" multiple accept="audio/*" style="display: none;" onchange="loadAndPlayMusic(event)">
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        // 1. INISIALISASI INDEXEDDB
        const DB_NAME = "KayoohDB";
        const STORE_NAME = "current_ride";
        let db;

        const request = indexedDB.open(DB_NAME, 1);
        request.onupgradeneeded = (e) => {
            db = e.target.result;
            if (!db.objectStoreNames.contains(STORE_NAME)) db.createObjectStore(STORE_NAME, { autoIncrement: true });
        };
        request.onsuccess = (e) => {
            db = e.target.result;
            restoreBlackBox();
        };

        function saveToIndexedDB(point) {
            if (!db) return;
            db.transaction(STORE_NAME, "readwrite").objectStore(STORE_NAME).add(point);
        }
        function clearIndexedDB() {
            if (db) db.transaction(STORE_NAME, "readwrite").objectStore(STORE_NAME).clear();
        }

        // 3. MAP & VARIABEL GOWES
        // Fallback default ke Jogja (biar nggak ke Samudra Atlantik)
        const map = L.map('map', { zoomControl: false, attributionControl: false }).setView([-7.801, 110.373], 15);
        // --- AUTO-NIGHT MODE MAP ---
        const currentHour = new Date().getHours();
        const isNight = (currentHour >= 18 || currentHour < 6); // Jam 6 sore sampai 6 pagi
        const tileUrl = isNight 
            ? 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png' 
            : 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
        
        L.tileLayer(tileUrl, { maxZoom: 19 }).addTo(map);

        const pathLine = L.polyline([], { color: '#e67e22', weight: 5 }).addTo(map); 
        const captainMarker = L.circleMarker([-7.801, 110.373], { radius: 8, color: '#fff', fillOpacity: 1, fillColor: '#e67e22', zIndexOffset: 1000 }).addTo(map);

        // --- MESIN DETEKSI LOKASI OTOMATIS (V6.0) ---
        if ("geolocation" in navigator) {
            navigator.geolocation.getCurrentPosition((position) => {
                const lat = position.coords.latitude;
                const lng = position.coords.longitude;
                
                // Pindahkan Peta dan Marker ke posisi device saat ini
                map.setView([lat, lng], 15);
                captainMarker.setLatLng([lat, lng]);
                
                console.log(`✅ Solo Mode: Lokasi terkunci di ${lat}, ${lng}`);
            }, (error) => {
                console.warn("⚠️ Akses lokasi ditolak, menggunakan koordinat default.");
            }, {
                enableHighAccuracy: true,
                timeout: 5000,
                maximumAge: 0
            });
        }

        // --- VARIABEL GLOBAL & SMART VOICE COACH ---
        let isRecording = false, isAutoPaused = false, watchId = null, timerInterval = null;
        let totalDistance = 0, maxSpeed = 0, lastPos = null;
        let accumulatedTimeMs = 0, currentStartTimeMs = 0, secondsElapsed = 0;
        let tempLog = [], lastTempFetch = 0, wakeLock = null;
        let nextVoiceMilestone = 5; 
        let nextHydrationMilestone = 20; // Target pengingat minum pertama (20 menit)

        // 3. FITUR SAKTI: WAKELOCK, SUHU & BLACKBOX
        async function requestWakeLock() {
            try { if ('wakeLock' in navigator) wakeLock = await navigator.wakeLock.request('screen'); } catch (e) {}
        }
        function releaseWakeLock() {
            if (wakeLock !== null) { wakeLock.release(); wakeLock = null; }
        }
        document.addEventListener('visibilitychange', () => {
            if (isRecording && document.visibilityState === 'visible') requestWakeLock();
        });

        async function fetchTemp(lat, lon) {
            try {
                let res = await fetch(`https://api.open-meteo.com/v1/forecast?latitude=${lat}&longitude=${lon}&current_weather=true`);
                let data = await res.json();
                return data.current_weather ? data.current_weather.temperature : null;
            } catch(e) { return null; }
        }

        function saveBlackBox() {
            localStorage.setItem('kayooh_single_state', JSON.stringify({
                totalDistance, maxSpeed, accumulatedTimeMs, isAutoPaused, currentStartTimeMs,
                tempLog, lastTempFetch, lastPos, room_id: "<?= $room_id ?>"
            }));
            localStorage.setItem('active_session', 'record_single.php?room=<?= $room_id ?>');
        }

        function clearBlackBox() {
            localStorage.removeItem('kayooh_single_state');
            localStorage.removeItem('active_session');
        }

        async function restoreBlackBox() {
            let state = localStorage.getItem('kayooh_single_state');
            if (state && localStorage.getItem('active_session')?.includes('record_single')) {
                let data = JSON.parse(state);
                totalDistance = data.totalDistance || 0; maxSpeed = data.maxSpeed || 0; nextVoiceMilestone = Math.floor(totalDistance / 5) * 5 + 5;
                // Pulihkan ingatan waktu minum
                nextHydrationMilestone = Math.floor((secondsElapsed / 60) / 20) * 20 + 20;
                accumulatedTimeMs = data.accumulatedTimeMs || 0; isAutoPaused = data.isAutoPaused || false;
                tempLog = data.tempLog || []; lastTempFetch = data.lastTempFetch || 0; lastPos = data.lastPos || null;

                document.getElementById('distVal').innerText = totalDistance.toFixed(2);
                
                const tx = db.transaction(STORE_NAME, "readonly");
                const allPoints = await new Promise(resolve => tx.objectStore(STORE_NAME).getAll().onsuccess = (e) => resolve(e.target.result));
                if (allPoints.length > 0) {
                    let latlngs = allPoints.map(p => [p.lat, p.lng]);
                    pathLine.setLatLngs(latlngs);
                    captainMarker.setLatLng(latlngs[latlngs.length - 1]);
                    map.fitBounds(pathLine.getBounds());
                }

                document.getElementById('btnStart').style.display = 'none';
                document.getElementById('btnStop').style.display = 'block';
                document.getElementById('btnStealth').style.display = 'flex';
                document.getElementById('btnMusic').style.display = 'flex';
                document.getElementById('gps-info').innerHTML = '<span style="color:#f39c12; font-weight:bold;">⚠️ SESI DIPULIHKAN DARI CRASH</span>';
                
                isRecording = true;
                currentStartTimeMs = Date.now(); 
                startWatch(); 
            }
        }

        // 4. MESIN UTAMA GPS
        function startRide() {
            if (!navigator.geolocation) { alert("GPS tidak didukung!"); return; }
            
            clearIndexedDB(); clearBlackBox();
            isRecording = true; isAutoPaused = false;
            currentStartTimeMs = Date.now(); accumulatedTimeMs = 0;
            totalDistance = 0; maxSpeed = 0; nextVoiceMilestone = 5; nextHydrationMilestone = 20; pathLine.setLatLngs([]); tempLog = []; lastPos = null;
            
            document.getElementById('btnStart').style.display = 'none';
            document.getElementById('btnStop').style.display = 'block';
            
            // --- PEMUNCULAN TOMBOL V8.0 & V9.0 ---
            document.getElementById('btnStealth').style.display = 'flex';

            document.getElementById('gps-info').innerHTML = '<span style="color:#2ecc71;">● MEREKAM (MENCARI SINYAL...)</span>';

            startWatch();
        }

        function startWatch() {
            requestWakeLock();
            
            timerInterval = setInterval(() => {
                if (isRecording) {
                    let totalMs = accumulatedTimeMs;
                    if (!isAutoPaused && currentStartTimeMs > 0) totalMs += (Date.now() - currentStartTimeMs);
                    secondsElapsed = Math.floor(totalMs / 1000);

                    const h = String(Math.floor(secondsElapsed / 3600)).padStart(2, '0');
                    const m = String(Math.floor((secondsElapsed % 3600) / 60)).padStart(2, '0');
                    const s = String(secondsElapsed % 60).padStart(2, '0');
                    document.getElementById('timeVal').innerText = `${h}:${m}:${s}`;

                    // --- HYDRATION COACH (Setiap 20 Menit Moving Time) ---
                    if (secondsElapsed >= (nextHydrationMilestone * 60)) {
                        if ('speechSynthesis' in window) {
                            const utterance = new SpeechSynthesisUtterance(`Kapten, waktu gowes sudah ${nextHydrationMilestone} menit. Jangan lupa minum air agar tetap hidrasi!`);
                            utterance.lang = 'id-ID';
                            utterance.rate = 0.95;
                            
                            // Efek Ducking (Kecilkan Musik)
                            if (!audioPlayer.paused) audioPlayer.volume = 0.15;
                            utterance.onend = function() {
                                audioPlayer.volume = 1.0;
                            };

                            window.speechSynthesis.speak(utterance);
                        }
                        nextHydrationMilestone += 20;
                    }
                }
            }, 1000);

            watchId = navigator.geolocation.watchPosition((pos) => {
                const { latitude, longitude, speed, altitude } = pos.coords;
                const currentPos = [latitude, longitude];
                const currentSpeed = (speed || 0) * 3.6;

                document.getElementById('speedVal').innerText = currentSpeed.toFixed(1);
                document.getElementById('elevVal').innerText = Math.round(altitude || 0);
                if (currentSpeed > maxSpeed) maxSpeed = currentSpeed;

                if (tempLog.length === 0 || (secondsElapsed - lastTempFetch > 900)) {
                    lastTempFetch = secondsElapsed;
                    fetchTemp(latitude, longitude).then(t => { if(t !== null) { tempLog.push(t); saveBlackBox(); }});
                }

                if (currentSpeed < 1.5) {
                    if (!isAutoPaused && isRecording) {
                        isAutoPaused = true;
                        if (currentStartTimeMs > 0) accumulatedTimeMs += (Date.now() - currentStartTimeMs);
                        currentStartTimeMs = 0;
                        document.getElementById('gps-info').innerHTML = '<span style="color:#f39c12;">⏸️ AUTO-PAUSE</span>';
                    }
                } else {
                    if (isAutoPaused && isRecording) {
                        isAutoPaused = false; currentStartTimeMs = Date.now();
                        document.getElementById('gps-info').innerHTML = '<span style="color:#2ecc71;">● MEREKAM</span>';
                    }

                    if (lastPos) {
                        const d = map.distance(lastPos, currentPos) / 1000;
                        if (d > 0.005) { 
                            totalDistance += d;
                            document.getElementById('distVal').innerText = totalDistance.toFixed(2);
                            saveToIndexedDB({ lat: latitude, lng: longitude, time: Date.now() });
                            pathLine.addLatLng(currentPos); captainMarker.setLatLng(currentPos); map.panTo(currentPos);
                            
                            lastPos = currentPos; 

                            // --- TRIGGER VOICE COACH TIAP KELIPATAN MILESTONE ---
                            if (totalDistance >= nextVoiceMilestone) {
                                let currentAvgSpeed = secondsElapsed > 0 ? (totalDistance / (secondsElapsed / 3600)) : 0;
                                announceStats(totalDistance, currentAvgSpeed);
                                nextVoiceMilestone += 5; // Set target ngoceh berikutnya
                            }
                        }
                    } else {
                        saveToIndexedDB({ lat: latitude, lng: longitude, time: Date.now() });
                        pathLine.addLatLng(currentPos); captainMarker.setLatLng(currentPos); map.panTo(currentPos);
                        document.getElementById('gps-info').innerHTML = '<span style="color:#2ecc71;">● MEREKAM</span>';
                        lastPos = currentPos;
                    }
                }

                saveBlackBox(); 
                fetch(`radar_sync.php?lat=${latitude}&lng=${longitude}&speed=${currentSpeed}&user=<?= $settings['captain_name'] ?? 'Kapten' ?>&room=<?= $room_id ?>`); 
            }, (err) => console.error(err), { enableHighAccuracy: true });
        }

        // --- ENGINE SMART VOICE COACH (v7.0 + v9.0 Ducking) ---
        function announceStats(distance, avgSpeed) {
            if ('speechSynthesis' in window) {
                window.speechSynthesis.cancel(); 
                const text = `Informasi Kayooh. Jarak tempuh: ${Math.floor(distance)} kilometer. Kecepatan rata rata: ${avgSpeed.toFixed(1)} kilometer per jam. Tetap semangat, Kapten!`;
                const utterance = new SpeechSynthesisUtterance(text);
                utterance.lang = 'id-ID';
                utterance.rate = 0.95;
                utterance.pitch = 1.0; 
                
                // Efek Ducking (Kecilkan Musik)
                if (!audioPlayer.paused) audioPlayer.volume = 0.15;
                utterance.onend = function() {
                    audioPlayer.volume = 1.0;
                };

                window.speechSynthesis.speak(utterance);
            }
        }

        // 5. PENUTUP & CHUNKING (Tanpa Prompt, Langsung Sikat!)
        async function finishRide() {
            clearInterval(timerInterval);
            if (watchId !== null) navigator.geolocation.clearWatch(watchId);
            releaseWakeLock();
            
            if (!isAutoPaused && currentStartTimeMs > 0) accumulatedTimeMs += (Date.now() - currentStartTimeMs);
            secondsElapsed = Math.floor(accumulatedTimeMs / 1000);

            document.getElementById('uploadOverlay').style.display = 'flex';
            
            const tx = db.transaction(STORE_NAME, "readonly");
            const allPoints = await new Promise(resolve => tx.objectStore(STORE_NAME).getAll().onsuccess = (e) => resolve(e.target.result));
            if (allPoints.length === 0) { cancelRide(); return; }

            let finalAvgTemp = 0;
            if (tempLog.length > 0) finalAvgTemp = tempLog.reduce((a, b) => a + b, 0) / tempLog.length;

            const CHUNK_SIZE = 500;
            const totalChunks = Math.ceil(allPoints.length / CHUNK_SIZE);
            const rideUUID = Date.now() + "_" + Math.floor(Math.random() * 1000);
            const finalAvgSpeed = secondsElapsed > 0 ? (totalDistance / (secondsElapsed / 3600)).toFixed(1) : 0;

            for (let i = 0; i < totalChunks; i++) {
                const chunk = allPoints.slice(i * CHUNK_SIZE, (i * CHUNK_SIZE) + CHUNK_SIZE);
                const formData = new FormData();
                formData.append('uuid', rideUUID);
                formData.append('chunk_index', i);
                formData.append('total_chunks', totalChunks);
                formData.append('points', JSON.stringify(chunk));
                
                if (i === totalChunks - 1) {
                    formData.append('distance', totalDistance);
                    formData.append('moving_time', secondsElapsed);
                    formData.append('avg_speed', finalAvgSpeed);
                    formData.append('max_speed', maxSpeed.toFixed(1));
                    formData.append('avg_temp', finalAvgTemp.toFixed(1));
                    formData.append('ride_name', "Solo Ride - " + new Date().toLocaleDateString('id-ID'));
                }

                await fetch('api_save_ride.php', { method: 'POST', body: formData });
                document.getElementById('progressFill').style.width = Math.round(((i + 1) / totalChunks) * 100) + "%";
            }

            clearIndexedDB(); clearBlackBox();
            window.location.href = 'dashboard.php';
        }

        function cancelRide() {
            if(confirm('Batalkan rekaman ini? Data akan hilang selamanya.')) {
                clearIndexedDB(); clearBlackBox();
                window.location.href = 'dashboard.php';
            }
        }

        function copyTrackingLink() {
            const url = `https://${window.location.hostname}${window.location.pathname.replace('record_single.php', 'radar.php')}?room=<?= $room_id ?>`;
            navigator.clipboard.writeText(url).then(() => alert("Link pelacakan berhasil disalin! Kirim ke grup!"));
        }

        // --- ENGINE STEALTH MODE v8.0 ---
        function enableStealth() {
            document.getElementById('stealthOverlay').style.display = 'flex';
            // Pastikan volume notifikasi tetap aman agar Ping! masih terdengar
            console.log("Stealth Mode Aktif: Menjaga baterai & Voice Coach tetap melek.");
        }

        function disableStealth() {
            document.getElementById('stealthOverlay').style.display = 'none';
            // Beri feedback sedikit getaran jika didukung HP
            if (navigator.vibrate) navigator.vibrate(50);
        }

        // ==========================================
        // KAYOOH LOCAL JUKEBOX (SHUFFLE NO-REPEAT)
        // ==========================================
        let playlist = [];
        let currentTrackIndex = 0;
        const audioPlayer = new Audio();

        // Algoritma Fisher-Yates Shuffle (Mengocok Array)
        function shuffleArray(array) {
            for (let i = array.length - 1; i > 0; i--) {
                const j = Math.floor(Math.random() * (i + 1));
                [array[i], array[j]] = [array[j], array[i]]; // Tukar posisi
            }
        }

        function loadAndPlayMusic(event) {
            const files = event.target.files;
            if (files.length === 0) return;

            playlist = Array.from(files); // Masukkan semua mp3 ke antrean
            shuffleArray(playlist);       // Kocok urutannya!
            currentTrackIndex = 0;        // Mulai dari lagu urutan pertama
            
            playCurrentTrack();
        }

        function playCurrentTrack() {
            if (playlist.length === 0) return;
            
            // Bersihkan memori Blob URL lagu sebelumnya agar RAM tidak bocor
            if (audioPlayer.src) URL.revokeObjectURL(audioPlayer.src);

            const file = playlist[currentTrackIndex];
            audioPlayer.src = URL.createObjectURL(file);
            audioPlayer.play();
            console.log("Memutar lagu: " + file.name);
        }

        // Sensor otomatis saat lagu habis
        audioPlayer.onended = function() {
            currentTrackIndex++; // Lanjut ke lagu berikutnya
            
            // Cek apakah ini lagu terakhir di antrean?
            if (currentTrackIndex >= playlist.length) {
                console.log("Playlist habis. Mengocok ulang 10 lagu!");
                shuffleArray(playlist); // Kocok ulang formasi!
                currentTrackIndex = 0;  // Mulai lagi dari indeks 0
            }
            playCurrentTrack();
        };
    </script>
</body>
</html>