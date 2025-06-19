// Configuración de la API
const API_URL = window.location.origin + '/api';
let authToken = localStorage.getItem('authToken');

// Función helper para requests
async function apiRequest(endpoint, options = {}) {
    const config = {
        ...options,
        headers: {
            'Content-Type': 'application/json',
            ...options.headers
        }
    };

    if (authToken) {
        config.headers['Authorization'] = `Bearer ${authToken}`;
    }

    try {
        const response = await fetch(`${API_URL}${endpoint}`, config);
        const data = await response.json();
        
        if (!response.ok) {
            if (response.status === 401) {
                localStorage.removeItem('authToken');
                localStorage.removeItem('currentUser');
                window.location.reload();
            }
            throw new Error(data.message || 'Error en la solicitud');
        }

        return data;
    } catch (error) {
        console.error('API Request Error:', error);
        throw error;
    }
}

// Funciones de autenticación actualizadas
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
            authToken = response.token;
            localStorage.setItem('authToken', authToken);
            localStorage.setItem('currentUser', JSON.stringify(response.user));
            
            currentUser = response.user;
            showDashboard();
        }
    } catch (error) {
        showError(error.message || 'Error al iniciar sesión');
    } finally {
        loginBtn.disabled = false;
        loginBtn.textContent = 'Iniciar Sesión';
    }
}

// Actualizar loadWhatsAppStatus
async function loadWhatsAppStatusAPI() {
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
        const response = await apiRequest('/whatsapp/status');
        
        if (response.success) {
            if (response.status === 'not_found') {
                whatsappStatus.innerHTML = '<p>No se encontró información de WhatsApp para este usuario.</p>';
            } else if (response.account.wa_conexion_status === 'connected') {
                showConnectedStatus(response.account.wa_instance_name);
            } else {
                await createWhatsAppInstanceAPI(response.account.wa_instance_name);
            }
        }
        
        whatsappLoading.style.display = 'none';
        whatsappContent.style.display = 'block';
        
    } catch (error) {
        console.error('Error cargando estado de WhatsApp:', error);
        whatsappLoading.style.display = 'none';
        whatsappContent.style.display = 'block';
        whatsappStatus.innerHTML = '<p>Error al cargar la información de WhatsApp.</p>';
    }
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