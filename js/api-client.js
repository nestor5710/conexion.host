// Configuración de la API
const API_URL = window.location.origin + '/api';
let authToken = localStorage.getItem('authToken');

// Variables globales
let currentUser = null;
let appConfig = null;
let whatsappCheckInterval = null;
let progressInterval = null;
let qrStartTime = null;

// Función helper para hacer requests a la API
async function apiRequest(endpoint, options = {}) {
    const config = {
        ...options,
        headers: {
            'Content-Type': 'application/json',
            ...options.headers
        }
    };

    // Asegurar que siempre se envíe el token actualizado
    const currentToken = authToken || localStorage.getItem('authToken');
    if (currentToken) {
        config.headers['Authorization'] = `Bearer ${currentToken}`;
        console.log('Enviando token:', currentToken.substring(0, 20) + '...'); // Solo mostrar parte del token
    } else {
        console.warn('No hay token disponible');
    }
    
    console.log('Request completo:', {
        url: `${API_URL}${endpoint}`,
        method: config.method || 'GET',
        hasAuth: !!config.headers['Authorization']
    });

    try {
        const response = await fetch(`${API_URL}${endpoint}`, config);
        
        // Primero verificar si la respuesta es JSON válida
        let data;
        try {
            data = await response.json();
        } catch (jsonError) {
            console.error('Error parsing JSON:', jsonError);
            throw new Error('Respuesta del servidor no válida');
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
            throw new Error(data.message || `Error ${response.status}: ${response.statusText}`);
        }

        return data;
    } catch (error) {
        console.error('API Request Error:', error);
        throw error;
    }
}

// Manejar login
async function handleLogin(e) {
    e.preventDefault();
    
    const username = document.getElementById('username').value;
    const password = document.getElementById('password').value;
    const loginBtn = document.getElementById('loginBtn');
    const errorMessage = document.getElementById('errorMessage');
    
    loginBtn.disabled = true;
    loginBtn.textContent = 'Verificando...';
    errorMessage.style.display = 'none';
    
    try {
        const response = await apiRequest('/auth/login', {
            method: 'POST',
            body: JSON.stringify({ username, password })
        });
        
        if (response.success) {
            // Actualizar token global inmediatamente
            authToken = response.token;
            localStorage.setItem('authToken', authToken);
            localStorage.setItem('currentUser', JSON.stringify(response.user));
            
            currentUser = response.user;
            console.log('Login exitoso, token guardado:', authToken.substring(0, 20) + '...');
            await showDashboard();
        }
    } catch (error) {
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
            authToken = savedToken; // Asegurar que la variable global esté actualizada
            currentUser = JSON.parse(savedUser);
            
            // Verificar que el token sea válido haciendo una petición de prueba
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
    if (whatsappCheckInterval && progressInterval) {
        if (qrStartTime) {
            const elapsedSeconds = Math.floor((Date.now() - qrStartTime) / 1000);
            const remainingSeconds = Math.max(0, 60 - elapsedSeconds);
            
            if (remainingSeconds > 0) {
                const progressFill = document.getElementById('progressFill');
                if (progressFill) {
                    const percentage = (remainingSeconds / 60) * 100;
                    progressFill.style.width = percentage + '%';
                    if (remainingSeconds <= 10) {
                        progressFill.style.background = 'linear-gradient(90deg, #e74c3c 0%, #c0392b 100%)';
                    }
                }
                return;
            }
        }
    }
    
    whatsappLoading.style.display = 'flex';
    whatsappContent.style.display = 'none';
    
    try {
        // Verificar que tenemos token antes de hacer la petición
        if (!authToken && !localStorage.getItem('authToken')) {
            throw new Error('No hay token de autenticación');
        }
        
        const response = await apiRequest('/whatsapp/status');
        
        if (response.success) {
            if (response.status === 'not_found') {
                whatsappStatus.innerHTML = '<p>No se encontró información de WhatsApp para este usuario.</p>';
            } else if (response.account && response.account.wa_conexion_status === 'connected') {
                showConnectedStatus(response.account.wa_instance_name);
            } else if (response.account) {
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

// Función de inicialización mejorada
document.addEventListener('DOMContentLoaded', async function() {
    console.log('Iniciando aplicación...');
    
    // Intentar verificar sesión existente
    const sessionValid = await verifySession();
    
    if (!sessionValid) {
        // Mostrar pantalla de login
        document.getElementById('loginScreen').style.display = 'block';
        document.getElementById('dashboard').style.display = 'none';
    }
    
    await loadAppConfig();
    setupEventListeners();
});

// Las demás funciones permanecen igual...
async function loadAppConfig() {
    try {
        if (currentUser && currentUser.logo_url) {
            const fixedLogo = document.getElementById('fixedLogo');
            fixedLogo.src = currentUser.logo_url;
            fixedLogo.style.display = 'block';
            
            if (currentUser.favicon_url) {
                document.getElementById('favicon').href = currentUser.favicon_url;
            }
        }
    } catch (error) {
        console.error('Error cargando configuración:', error);
    }
}

function setupEventListeners() {
    // Login form
    document.getElementById('loginForm').addEventListener('submit', handleLogin);
    
    // Logout button
    document.getElementById('logoutBtn').addEventListener('click', handleLogout);
    
    // Tabs
    document.querySelectorAll('.tab').forEach(tab => {
        tab.addEventListener('click', function() {
            switchTab(this.dataset.tab);
        });
    });
}

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
    
    // Redireccionar según la configuración
    if (appConfig && appConfig.logout_redirect_url) {
        window.location.href = appConfig.logout_redirect_url;
    } else {
        // Volver a la pantalla de login
        document.getElementById('dashboard').style.display = 'none';
        document.getElementById('loginScreen').style.display = 'block';
        
        // Limpiar formulario
        document.getElementById('loginForm').reset();
        document.getElementById('errorMessage').style.display = 'none';
    }
}

// Exponer funciones globales necesarias
window.loadWhatsAppStatus = loadWhatsAppStatus;
window.regenerateQR = async function(instanceName) {
    document.getElementById('whatsappLoading').style.display = 'flex';
    document.getElementById('whatsappContent').style.display = 'none';
    await createWhatsAppInstance(instanceName);
    document.getElementById('whatsappLoading').style.display = 'none';
    document.getElementById('whatsappContent').style.display = 'block';
}

// Actualizar createWhatsAppInstance
async function createWhatsAppInstanceAPI(instanceName) {
    try {
        const response = await apiRequest('/whatsapp/create-instance', {
            method: 'POST',
            body: JSON.stringify({ instanceName })
        });
        
        if (response.success && response.qrcode) {
            displayQRCode(response.qrcode.base64 || response.qrcode, instanceName);
            startConnectionPollingAPI(instanceName);
        } else {
            throw new Error('No se pudo generar el QR');
        }
        
    } catch (error) {
        console.error('Error creando instancia:', error);
        document.getElementById('whatsappStatus').innerHTML = `
            <p>Error al crear la instancia de WhatsApp.</p>
            <button class="regenerate-btn" onclick="regenerateQR('${instanceName}')">Intentar de nuevo</button>
        `;
    }
}

// Actualizar polling
function startConnectionPollingAPI(instanceName) {
    // Mantener la lógica existente pero actualizar la verificación
    if (!whatsappCheckInterval || !progressInterval) {
        // ... código existente de polling ...
        
        whatsappCheckInterval = setInterval(async () => {
            attempts++;
            
            try {
                const response = await apiRequest(`/whatsapp/check-connection/${instanceName}`);
                
                if (response.success && response.status) {
                    const isConnected = response.status.state === 'open' || 
                                      response.status.instance?.state === 'open';
                    
                    if (isConnected) {
                        clearInterval(whatsappCheckInterval);
                        clearInterval(progressInterval);
                        whatsappCheckInterval = null;
                        progressInterval = null;
                        qrStartTime = null;
                        
                        // Actualizar estado
                        await apiRequest('/whatsapp/update-status', {
                            method: 'POST',
                            body: JSON.stringify({
                                status: 'connected',
                                instanceName: instanceName
                            })
                        });
                        
                        showConnectedStatus(instanceName);
                    }
                }
                
                // ... resto del código de polling ...
                
            } catch (error) {
                console.error('Error verificando conexión:', error);
            }
        }, 5000);
    }
}