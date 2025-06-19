// Configuración de la API mejorada
const API_URL = window.location.origin + '/api';
let authToken = localStorage.getItem('authToken');

// Variables globales
let currentUser = null;
let appConfig = null;
let whatsappCheckInterval = null;
let progressInterval = null;
let qrStartTime = null;

// Función helper mejorada para hacer requests a la API
async function apiRequest(endpoint, options = {}) {
    const config = {
        ...options,
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            ...options.headers
        }
    };

    // Asegurar que siempre se envíe el token actualizado
    const currentToken = authToken || localStorage.getItem('authToken');
    if (currentToken) {
        config.headers['Authorization'] = `Bearer ${currentToken}`;
        console.log('Enviando token:', currentToken.substring(0, 20) + '...');
    }
    
    console.log('Request completo:', {
        url: `${API_URL}${endpoint}`,
        method: config.method || 'GET',
        hasAuth: !!config.headers['Authorization'],
        headers: Object.keys(config.headers)
    });

    try {
        const response = await fetch(`${API_URL}${endpoint}`, config);
        
        // Verificar si la respuesta es JSON
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            console.error('Respuesta no es JSON:', response.status, response.statusText);
            const textResponse = await response.text();
            console.error('Contenido de respuesta:', textResponse.substring(0, 500));
            throw new Error(`El servidor devolvió ${response.status}: ${response.statusText}. Respuesta no es JSON.`);
        }
        
        let data;
        try {
            data = await response.json();
        } catch (jsonError) {
            console.error('Error parsing JSON:', jsonError);
            throw new Error('Respuesta del servidor no válida (JSON malformado)');
        }
        
        if (!response.ok) {
            if (response.status === 401) {
                console.error('Token inválido o expirado');
                localStorage.removeItem('authToken');
                localStorage.removeItem('currentUser');
                authToken = null;
                currentUser = null;
                window.location.reload();
                return;
            }
            
            const errorMessage = data.message || `Error ${response.status}: ${response.statusText}`;
            console.error('API Error:', errorMessage, data.debug);
            throw new Error(errorMessage);
        }

        return data;
    } catch (error) {
        console.error('API Request Error:', error);
        throw error;
    }
}

// Manejar login mejorado
async function handleLogin(e) {
    e.preventDefault();
    
    const username = document.getElementById('username').value.trim();
    const password = document.getElementById('password').value;
    const loginBtn = document.getElementById('loginBtn');
    const errorMessage = document.getElementById('errorMessage');
    
    // Validaciones básicas
    if (!username || !password) {
        showError('Por favor complete todos los campos');
        return;
    }
    
    loginBtn.disabled = true;
    loginBtn.textContent = 'Verificando...';
    errorMessage.style.display = 'none';
    
    try {
        console.log('Intentando login para usuario:', username);
        
        const response = await apiRequest('/auth/login', {
            method: 'POST',
            body: JSON.stringify({ username, password })
        });
        
        console.log('Respuesta de login:', response);
        
        if (response.success) {
            // Actualizar token global inmediatamente
            authToken = response.token;
            localStorage.setItem('authToken', authToken);
            localStorage.setItem('currentUser', JSON.stringify(response.user));
            
            currentUser = response.user;
            console.log('Login exitoso, token guardado');
            await showDashboard();
        } else {
            throw new Error(response.message || 'Login fallido');
        }
    } catch (error) {
        console.error('Error en login:', error);
        showError(error.message || 'Error al iniciar sesión');
    } finally {
        loginBtn.disabled = false;
        loginBtn.textContent = 'Iniciar Sesión';
    }
}

// Verificar sesión al cargar
async function verifySession() {
    const savedUser = localStorage.getItem('currentUser');
    const savedToken = localStorage.getItem('authToken');
    
    if (savedUser && savedToken) {
        try {
            authToken = savedToken;
            currentUser = JSON.parse(savedUser);
            
            console.log('Verificando sesión existente...');
            
            // Verificar que el token sea válido
            const profile = await apiRequest('/auth/profile');
            if (profile.success) {
                currentUser = profile.user;
                localStorage.setItem('currentUser', JSON.stringify(currentUser));
                await showDashboard();
                return true;
            }
        } catch (error) {
            console.error('Error verificando sesión:', error);
            // Limpiar datos inválidos
            localStorage.removeItem('authToken');
            localStorage.removeItem('currentUser');
            authToken = null;
            currentUser = null;
        }
    }
    return false;
}

// Cargar estado de WhatsApp con mejor manejo de errores
async function loadWhatsAppStatus() {
    const whatsappLoading = document.getElementById('whatsappLoading');
    const whatsappContent = document.getElementById('whatsappContent');
    const whatsappStatus = document.getElementById('whatsappStatus');
    
    // Si hay un QR activo, no recargar
    if (whatsappCheckInterval && progressInterval && qrStartTime) {
        const elapsedSeconds = Math.floor((Date.now() - qrStartTime) / 1000);
        const remainingSeconds = Math.max(0, 60 - elapsedSeconds);
        
        if (remainingSeconds > 0) {
            updateProgressBar(remainingSeconds);
            return;
        }
    }
    
    whatsappLoading.style.display = 'flex';
    whatsappContent.style.display = 'none';
    
    try {
        // Verificar que tenemos token antes de hacer la petición
        if (!authToken && !localStorage.getItem('authToken')) {
            throw new Error('No hay token de autenticación');
        }
        
        console.log('Cargando estado de WhatsApp...');
        const response = await apiRequest('/whatsapp/status');
        
        console.log('Respuesta de WhatsApp status:', response);
        
        if (response.success) {
            if (response.status === 'not_found') {
                whatsappStatus.innerHTML = '<p>No se encontró información de WhatsApp para este usuario.</p>';
            } else if (response.account && response.account.wa_conexion_status === 'connected') {
                showConnectedStatus(response.account.wa_instance_name);
            } else if (response.account && response.account.wa_instance_name) {
                await createWhatsAppInstance(response.account.wa_instance_name);
            } else {
                whatsappStatus.innerHTML = '<p>No hay información de cuenta disponible.</p>';
            }
        } else {
            throw new Error(response.message || 'Respuesta no exitosa del servidor');
        }
        
    } catch (error) {
        console.error('Error cargando estado de WhatsApp:', error);
        whatsappStatus.innerHTML = `
            <div style="color: #e74c3c; padding: 15px; background: #ffeaea; border-radius: 5px;">
                <strong>Error:</strong> ${error.message}
                <br><br>
                <button class="regenerate-btn" onclick="loadWhatsAppStatus()" style="background: #3498db; color: white;">
                    Reintentar
                </button>
            </div>
        `;
    } finally {
        whatsappLoading.style.display = 'none';
        whatsappContent.style.display = 'block';
    }
}

// Crear instancia de WhatsApp mejorado
async function createWhatsAppInstance(instanceName) {
    try {
        console.log('Creando instancia de WhatsApp:', instanceName);
        
        const response = await apiRequest('/whatsapp/create-instance', {
            method: 'POST',
            body: JSON.stringify({ instanceName })
        });
        
        console.log('Respuesta de crear instancia:', response);
        
        if (response.success && response.qrcode) {
            displayQRCode(response.qrcode.base64 || response.qrcode, instanceName);
            startConnectionPolling(instanceName);
        } else {
            throw new Error('No se pudo generar el QR');
        }
        
    } catch (error) {
        console.error('Error creando instancia:', error);
        document.getElementById('whatsappStatus').innerHTML = `
            <div style="color: #e74c3c; padding: 15px; background: #ffeaea; border-radius: 5px;">
                <strong>Error:</strong> ${error.message}
                <br><br>
                <button class="regenerate-btn" onclick="regenerateQR('${instanceName}')">Intentar de nuevo</button>
            </div>
        `;
    }
}

// Actualizar barra de progreso
function updateProgressBar(remainingSeconds) {
    const progressFill = document.getElementById('progressFill');
    if (progressFill) {
        const percentage = (remainingSeconds / 60) * 100;
        progressFill.style.width = percentage + '%';
        if (remainingSeconds <= 10) {
            progressFill.style.background = 'linear-gradient(90deg, #e74c3c 0%, #c0392b 100%)';
        }
    }
}

// Mostrar QR code mejorado
function displayQRCode(qrcodeBase64, instanceName) {
    const qrSrc = qrcodeBase64.startsWith('data:image') ? qrcodeBase64 : `data:image/png;base64,${qrcodeBase64}`;
    
    document.getElementById('whatsappStatus').innerHTML = `
        <div class="qr-progress-container">
            <div class="qr-progress-bar" style="width: 100%; height: 8px; background: #e9ecef; border-radius: 4px; overflow: hidden;">
                <div class="qr-progress-fill" id="progressFill" style="height: 100%; background: linear-gradient(90deg, #27ae60 0%, #2ecc71 100%); width: 100%; transition: width 1s linear;"></div>
            </div>
        </div>
        <div class="qr-container" style="display: flex; align-items: flex-start; gap: 30px; margin-top: 15px;">
            <div class="qr-instructions" style="flex: 1;">
                <h4>Escanea el código QR con WhatsApp</h4>
                <ol>
                    <li>Abre WhatsApp en tu teléfono</li>
                    <li>Ve a Configuración > Dispositivos vinculados</li>
                    <li>Toca "Vincular un dispositivo"</li>
                    <li>Escanea el código QR</li>
                </ol>
                <p class="qr-expired" style="display: none; margin-top: 15px; color: #e74c3c;">El código QR ha expirado</p>
                <button class="regenerate-btn" onclick="regenerateQR('${instanceName}')" style="display: none;">Generar nuevo QR</button>
            </div>
            <div class="qr-code" style="max-width: 200px; padding: 10px; background: white; border: 1px solid #dee2e6; border-radius: 8px; flex-shrink: 0;">
                <img src="${qrSrc}" alt="QR Code" style="width: 100%; height: auto;" />
            </div>
        </div>
    `;
}

// Mostrar estado conectado
function showConnectedStatus(instanceName) {
    document.getElementById('whatsappStatus').innerHTML = `
        <div class="status-connected">
            ✓ Conectado
        </div>
        <div class="info-item" style="margin-top: 20px;">
            <span class="info-label">Instancia:</span>
            <span class="info-value">${instanceName}</span>
        </div>
        <div class="info-item">
            <span class="info-label">Estado:</span>
            <span class="info-value">Activo</span>
        </div>
        <div class="info-item">
            <span class="info-label">Fecha de conexión:</span>
            <span class="info-value">${new Date().toLocaleString()}</span>
        </div>
    `;
}

// Iniciar polling para verificar conexión mejorado
function startConnectionPolling(instanceName) {
    // Limpiar intervalos existentes
    if (whatsappCheckInterval) {
        clearInterval(whatsappCheckInterval);
        whatsappCheckInterval = null;
    }
    if (progressInterval) {
        clearInterval(progressInterval);
        progressInterval = null;
    }
    
    qrStartTime = Date.now();
    
    let attempts = 0;
    const maxAttempts = 12; // 60 segundos / 5 segundos = 12 intentos
    let remainingSeconds = 60;
    
    console.log('Iniciando polling para verificar conexión de:', instanceName);
    
    // Actualizar barra de progreso cada segundo
    progressInterval = setInterval(() => {
        remainingSeconds--;
        updateProgressBar(remainingSeconds);
        
        if (remainingSeconds <= 0) {
            clearInterval(progressInterval);
            progressInterval = null;
        }
    }, 1000);
    
    // Verificar conexión cada 5 segundos
    whatsappCheckInterval = setInterval(async () => {
        attempts++;
        console.log(`Verificando conexión - Intento ${attempts} de ${maxAttempts}`);
        
        try {
            const response = await apiRequest(`/whatsapp/check-connection/${instanceName}`);
            
            if (response.success && response.status) {
                console.log('Estado de conexión:', response.status);
                
                const isConnected = response.status.state === 'open' || 
                                  response.status.instance?.state === 'open' ||
                                  response.status.status === 'connected';
                
                if (isConnected) {
                    console.log('¡Conexión detectada!');
                    clearInterval(whatsappCheckInterval);
                    clearInterval(progressInterval);
                    whatsappCheckInterval = null;
                    progressInterval = null;
                    qrStartTime = null;
                    
                    // Actualizar estado en el servidor
                    try {
                        await apiRequest('/whatsapp/update-status', {
                            method: 'POST',
                            body: JSON.stringify({
                                status: 'connected',
                                instanceName: instanceName
                            })
                        });
                    } catch (updateError) {
                        console.error('Error actualizando estado:', updateError);
                    }
                    
                    showConnectedStatus(instanceName);
                    return;
                }
            }
            
            if (attempts >= maxAttempts) {
                clearInterval(whatsappCheckInterval);
                clearInterval(progressInterval);
                whatsappCheckInterval = null;
                progressInterval = null;
                qrStartTime = null;
                
                const qrExpired = document.querySelector('.qr-expired');
                const regenerateBtn = document.querySelector('.regenerate-btn');
                const progressContainer = document.querySelector('.qr-progress-container');
                
                if (qrExpired) qrExpired.style.display = 'block';
                if (regenerateBtn) regenerateBtn.style.display = 'inline-block';
                if (progressContainer) progressContainer.style.display = 'none';
                
                console.log('Polling detenido: QR expirado');
            }
            
        } catch (error) {
            console.error('Error verificando conexión:', error);
        }
        
    }, 5000);
}

// Regenerar QR
window.regenerateQR = async function(instanceName) {
    console.log('Regenerando QR para:', instanceName);
    document.getElementById('whatsappLoading').style.display = 'flex';
    document.getElementById('whatsappContent').style.display = 'none';
    await createWhatsAppInstance(instanceName);
    document.getElementById('whatsappLoading').style.display = 'none';
    document.getElementById('whatsappContent').style.display = 'block';
}

// Funciones auxiliares existentes...
function showError(message) {
    const errorMessage = document.getElementById('errorMessage');
    errorMessage.textContent = message;
    errorMessage.style.display = 'block';
}

function handleLogout() {
    // Limpiar intervalos
    if (whatsappCheckInterval) {
        clearInterval(whatsappCheckInterval);
        whatsappCheckInterval = null;
    }
    if (progressInterval) {
        clearInterval(progressInterval);
        progressInterval = null;
    }
    
    // Limpiar storage
    localStorage.removeItem('authToken');
    localStorage.removeItem('currentUser');
    authToken = null;
    currentUser = null;
    
    // Volver a la pantalla de login
    document.getElementById('dashboard').style.display = 'none';
    document.getElementById('loginScreen').style.display = 'block';
    
    // Limpiar formulario
    document.getElementById('loginForm').reset();
    document.getElementById('errorMessage').style.display = 'none';
}

// Mostrar dashboard
async function showDashboard() {
    document.getElementById('loginScreen').style.display = 'none';
    document.getElementById('dashboard').style.display = 'block';
    
    // Mostrar información del usuario
    document.getElementById('userName').textContent = currentUser.name || currentUser.username;
    
    if (currentUser.avatar_url) {
        document.getElementById('userAvatar').src = currentUser.avatar_url;
    }
    
    // Actualizar logo y favicon
    if (currentUser.logo_url) {
        const fixedLogo = document.getElementById('fixedLogo');
        fixedLogo.src = currentUser.logo_url;
        fixedLogo.style.display = 'block';
    }
    
    if (currentUser.favicon_url) {
        document.getElementById('favicon').href = currentUser.favicon_url;
    }
    
    // Crear pestañas dinámicas
    createDynamicTabs();
    
    // Cargar contenido
    await loadAccountInfo();
}

// Cambiar pestaña
function switchTab(tabName) {
    // Actualizar botones de pestañas
    document.querySelectorAll('.tab').forEach(tab => {
        tab.classList.remove('active');
    });
    document.querySelector(`[data-tab="${tabName}"]`).classList.add('active');
    
    // Actualizar contenido
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.remove('active');
    });
    
    const targetTab = document.getElementById(`${tabName}Tab`);
    if (targetTab) {
        targetTab.classList.add('active');
        
        // Si es la pestaña de WhatsApp, cargar su contenido
        if (tabName === 'whatsapp' && currentUser.whatsapp_login) {
            loadWhatsAppStatus();
        }
    }
}

// Crear pestañas dinámicas (función existente)
function createDynamicTabs() {
    const tabsContainer = document.getElementById('tabsContainer');
    const socialNetworks = [
        { 
            key: 'whatsapp_login', 
            name: 'WhatsApp', 
            tabId: 'whatsapp',
            icon: `<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg>`
        },
        { 
            key: 'google_login', 
            name: 'Google', 
            tabId: 'google',
            icon: `<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>`
        },
        { 
            key: 'facebook_login', 
            name: 'Facebook', 
            tabId: 'facebook',
            icon: `<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>`
        },
        { 
            key: 'instagram_login', 
            name: 'Instagram', 
            tabId: 'instagram',
            icon: `<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zM5.838 12a6.162 6.162 0 1 1 12.324 0 6.162 6.162 0 0 1-12.324 0zM12 16a4 4 0 1 1 0-8 4 4 0 0 1 0 8zm4.965-10.405a1.44 1.44 0 1 1 2.881.001 1.44 1.44 0 0 1-2.881-.001z"/></svg>`
        },
        { 
            key: 'tiktok_login', 
            name: 'TikTok', 
            tabId: 'tiktok',
            icon: `<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z"/></svg>`
        }
    ];

    // Limpiar pestañas existentes (excepto la primera)
    const existingTabs = tabsContainer.querySelectorAll('.tab:not(:first-child)');
    existingTabs.forEach(tab => tab.remove());

    socialNetworks.forEach(network => {
        if (currentUser[network.key] === true) {
            const tabButton = document.createElement('button');
            tabButton.className = 'tab';
            tabButton.dataset.tab = network.tabId;
            tabButton.innerHTML = `${network.icon}<span>${network.name}</span>`;
            tabButton.addEventListener('click', function() {
                switchTab(this.dataset.tab);
            });
            tabsContainer.appendChild(tabButton);
        }
    });
}

// Cargar información de la cuenta
async function loadAccountInfo() {
    try {
        const personalInfo = document.getElementById('personalInfo');
        personalInfo.innerHTML = `
            <div class="info-item">
                <span class="info-label">Nombre de Usuario:</span>
                <span class="info-value">${currentUser.username}</span>
            </div>
            <div class="info-item">
                <span class="info-label">Nombre Completo:</span>
                <span class="info-value">${currentUser.name || 'No especificado'}</span>
            </div>
            <div class="info-item">
                <span class="info-label">Email:</span>
                <span class="info-value">${currentUser.email || 'No especificado'}</span>
            </div>
            <div class="info-item">
                <span class="info-label">Fecha de Registro:</span>
                <span class="info-value">${currentUser.created_at ? new Date(currentUser.created_at).toLocaleString() : 'No especificado'}</span>
            </div>
            <div class="info-item">
                <span class="info-label">Última Conexión:</span>
                <span class="info-value">${currentUser.last_conexion ? new Date(currentUser.last_conexion).toLocaleString() : 'Primera conexión'}</span>
            </div>
        `;
        
        document.getElementById('accountLoading').style.display = 'none';
        document.getElementById('accountContent').style.display = 'block';
    } catch (error) {
        console.error('Error cargando información de cuenta:', error);
        document.getElementById('accountLoading').innerHTML = '<p>Error cargando información</p>';
    }
}

// Función de inicialización mejorada
async function initializeApp() {
    console.log('Iniciando aplicación...');
    
    try {
        // Intentar verificar sesión existente
        const sessionValid = await verifySession();
        
        if (!sessionValid) {
            // Mostrar pantalla de login
            document.getElementById('loginScreen').style.display = 'block';
            document.getElementById('dashboard').style.display = 'none';
        }
        
        setupEventListeners();
        
    } catch (error) {
        console.error('Error durante la inicialización:', error);
        // Mostrar pantalla de login en caso de error
        document.getElementById('loginScreen').style.display = 'block';
        document.getElementById('dashboard').style.display = 'none';
    }
}

// Configurar event listeners
function setupEventListeners() {
    // Login form
    const loginForm = document.getElementById('loginForm');
    if (loginForm) {
        loginForm.addEventListener('submit', handleLogin);
    }
    
    // Logout button
    const logoutBtn = document.getElementById('logoutBtn');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', handleLogout);
    }
    
    // Tabs existentes
    document.querySelectorAll('.tab').forEach(tab => {
        tab.addEventListener('click', function() {
            switchTab(this.dataset.tab);
        });
    });
}

// Función para depuración
function debugAPI() {
    console.log('Estado actual de la aplicación:');
    console.log('- authToken:', authToken ? authToken.substring(0, 20) + '...' : 'null');
    console.log('- currentUser:', currentUser);
    console.log('- localStorage token:', localStorage.getItem('authToken') ? 'existe' : 'null');
    console.log('- localStorage user:', localStorage.getItem('currentUser') ? 'existe' : 'null');
}

// Exponer funciones para depuración
window.debugAPI = debugAPI;
window.loadWhatsAppStatus = loadWhatsAppStatus;

// Inicializar cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', initializeApp);
