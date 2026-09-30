const { createApp } = Vue;

const app = createApp({
  data() {
    return {
      // Acceso
      token: '',
      nombre_alumno: '',
      errorAcceso: false,
      errorAccesoMsg: '',
      cargando: false,

      // Estado de examen y proctoring
      examenIniciado: false,
      incidencias_foco: 0,
      incidencias_copia: 0,
      incidencias_seleccion: 0,
      bitacora: [],
      respuestasAlumno: {},
      
      // Alerta / Backdrop
      alertaActiva: false,
      mensajeAlerta: '',

      // Pantallas
      pantallaActual: 'login', // 'login' | 'examen' | 'finalizado'

      // Datos y temporizador
      examenData: null,
      tiempoRestanteMinutos: 0,
      intervaloTiempo: null,

      urlBase: 'https://your_url/api/'
    };
  },

  mounted() {
    this.escucharEventosProctoring();
  },

  computed: {
    tiempoFormateado() {
      const horas = Math.floor(this.tiempoRestanteMinutos / 60);
      const minutos = this.tiempoRestanteMinutos % 60;
      return `${String(horas).padStart(2, '0')}:${String(minutos).padStart(2, '0')}`;
    },

    preguntasContestadas() {
      if (!this.respuestasAlumno) return 0;
      return Object.values(this.respuestasAlumno).filter(val => {
        if (val === null || val === undefined) return false;
        if (typeof val === 'string') return val.trim().length > 0;
        return true;
      }).length;
    }
  },

  methods: {
    async iniciarExamen() {
      if (!this.token.trim() || !this.nombre_alumno.trim()) {
        this.errorAcceso = true;
        this.errorAccesoMsg = 'Por favor ingresa el token y tu nombre.';
        return;
      }

      this.cargando = true;
      this.errorAcceso = false;
      this.errorAccesoMsg = '';

      try {
        const response = await fetch(this.urlBase + 'validar_acceso.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            token: this.token,
            nombre_alumno: this.nombre_alumno
          })
        });

        const data = await response.json();

        if (response.ok && data.success) {
          this.examenData = data;
          this.examenIniciado = true;
          
          // Inicializar plantilla base y restaurar datos guardados
          this.inicializarRespuestas();
          this.pantallaActual = 'examen';
          this.restaurarRespuestasLocal();

          // 1. ELIMINADO: Ya no se registra el inicio como incidencia
          this.iniciarContador();
        } else {
          this.errorAcceso = true;
          this.errorAccesoMsg = data.error || 'Token o datos inválidos.';
        }
      } catch (error) {
        console.error('Error al validar acceso:', error);
        this.errorAcceso = true;
        this.errorAccesoMsg = 'Error de conexión con el servidor de validación.';
      } finally {
        this.cargando = false;
      }
    },

    // 5. RESALTADO PYTHON CON PRISMJS
    obtenerCodigoResaltado(codigoTexto) {
      if (!codigoTexto) return '';
      if (typeof Prism !== 'undefined' && Prism.languages.python) {
        return Prism.highlight(codigoTexto, Prism.languages.python, 'python');
      }
      return codigoTexto;
    },

    sincronizarScroll(e) {
      const textarea = e.target;
      const container = textarea.parentElement;
      const pre = container ? container.querySelector('pre') : null;
      if (pre) {
        pre.scrollTop = textarea.scrollTop;
        pre.scrollLeft = textarea.scrollLeft;
      }
    },

    insertarTabulacion(preguntaId, e) {
      const textarea = e.target;
      const inicio = textarea.selectionStart;
      const fin = textarea.selectionEnd;
      const valorActual = this.respuestasAlumno[preguntaId] || '';

      const nuevoValor = valorActual.substring(0, inicio) + "    " + valorActual.substring(fin);
      this.guardarRespuesta(preguntaId, nuevoValor);

      this.$nextTick(() => {
        textarea.selectionStart = textarea.selectionEnd = inicio + 4;
      });
    },

    // Gestión de almacenamiento local
    obtenerClaveStorage() {
      const intentoId = this.examenData?.intento?.id;
      return intentoId ? `intento_respuestas_${intentoId}` : null;
    },

    inicializarRespuestas() {
      const resp = {};
      if (this.examenData?.preguntas) {
        this.examenData.preguntas.forEach(p => {
          if (p.tipo === 'codigo') {
            resp[p.id] = p.codigo_plantilla;
          } else {
            resp[p.id] = '';
          }
        });
      }
      this.respuestasAlumno = resp;
    },

    restaurarRespuestasLocal() {
      const clave = this.obtenerClaveStorage();
      if (!clave) return;

      const guardado = localStorage.getItem(clave);
      if (guardado) {
        try {
          const recuperado = JSON.parse(guardado);
          this.respuestasAlumno = { ...this.respuestasAlumno, ...recuperado };
        } catch (e) {
          console.warn('Error al restaurar localStorage:', e);
        }
      }
    },

    guardarRespuestasLocal() {
      const clave = this.obtenerClaveStorage();
      if (!clave) return;
      localStorage.setItem(clave, JSON.stringify(this.respuestasAlumno));
    },

    limpiarRespuestasLocal() {
      const clave = this.obtenerClaveStorage();
      if (clave) localStorage.removeItem(clave);
    },

    guardarRespuesta(preguntaId, valor) {
      this.respuestasAlumno[preguntaId] = valor;
      this.guardarRespuestasLocal();
    },

    // PROCTORING & ALERTAS
    registrarIncidencia(tipo, detalle) {
      if (!this.examenIniciado) return;

      const incidencia = {
        id: crypto.randomUUID ? crypto.randomUUID() : Date.now().toString(36),
        timestamp: new Date().toISOString(),
        tipo: tipo,
        detalle: detalle
      };

      this.bitacora.push(incidencia);
      this.mostrarAlerta(`Incidencia detectada (${tipo}): ${detalle}`);
    },

    mostrarAlerta(mensaje) {
      this.mensajeAlerta = mensaje;
      this.alertaActiva = true;
    },

    cerrarAlerta() {
      this.alertaActiva = false;
    },

    escucharEventosProctoring() {
      // 2. Pérdida de foco
      window.addEventListener('blur', () => {
        if (!this.examenIniciado) return;
        this.incidencias_foco++;
        this.registrarIncidencia('PERDIDA_FOCO', 'Cambio de ventana o pestaña detectado.');
      });

      // 3. Intento de copia
      document.addEventListener('copy', (e) => {
        if (!this.examenIniciado) return;
        this.incidencias_copia++;
        this.registrarIncidencia('INTENTO_COPIA', 'Se intentó copiar contenido del examen.');
      });

      // 4. Selección de texto
      document.addEventListener('selectionchange', () => {
        if (!this.examenIniciado) return;
        const seleccion = window.getSelection().toString().trim();
        if (seleccion.length > 5) { // Se activa cuando selecciona más de 5 caracteres
          this.incidencias_seleccion++;
          this.registrarIncidencia('SELECCION_TEXTO', `Selección de texto detectada ("${seleccion.slice(0, 15)}...")`);
        }
      });
    },

    // Envío final
    async finalizarExamen() {
      this.examenIniciado = false;
      this.detenerContador();

      const respuestasFormateadas = Object.entries(this.respuestasAlumno).map(([pId, val]) => ({
        pregunta_id: parseInt(pId, 10),
        respuesta: val
      }));

      const payloadEnvio = {
        token: this.token,
        nombre_alumno: this.nombre_alumno,
        intento_id: this.examenData.intento.id,
        respuestas: respuestasFormateadas,
        incidencias_foco: this.incidencias_foco,
        incidencias_copia: this.incidencias_copia,
        incidencias_seleccion: this.incidencias_seleccion,
        registro_incidencias: JSON.stringify(this.bitacora)
      };

      try {
        const response = await fetch(this.urlBase + 'guardar_respuestas.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payloadEnvio)
        });

        const data = await response.json();

        if (response.ok && data.success) {
          this.limpiarRespuestasLocal();
          this.pantallaActual = 'finalizado';
        } else {
          this.errorAcceso = true;
          this.errorAccesoMsg = data.error || 'Ocurrió un error al enviar tu respuesta.';
        }
      } catch (error) {
        console.error('Error al enviar respuestas:', error);
        this.errorAcceso = true;
        this.errorAccesoMsg = 'Error de conexión con el servidor al guardar el examen.';
      }
    },

    iniciarContador() {
      const duracionTotal = parseInt(this.examenData.examen.duracion_minutos) || 0;
      const fechaInicioStr = this.examenData.intento?.fecha_inicio;

      if (fechaInicioStr) {
        const fechaInicio = new Date(fechaInicioStr.replace(' ', 'T'));
        const ahora = new Date();
        const minutosTranscurridos = Math.floor((ahora - fechaInicio) / 1000 / 60);
        const minutosRestantes = duracionTotal - minutosTranscurridos;
        this.tiempoRestanteMinutos = minutosRestantes > 0 ? minutosRestantes : 0;
      } else {
        this.tiempoRestanteMinutos = duracionTotal;
      }

      if (this.tiempoRestanteMinutos <= 0) {
        this.tiempoAgotado();
        return;
      }

      if (this.intervaloTiempo) clearInterval(this.intervaloTiempo);

      this.intervaloTiempo = setInterval(() => {
        if (this.tiempoRestanteMinutos > 1) {
          this.tiempoRestanteMinutos--;
        } else {
          this.tiempoRestanteMinutos = 0;
          this.detenerContador();
          this.tiempoAgotado();
        }
      }, 60000);
    },

    detenerContador() {
      if (this.intervaloTiempo) {
        clearInterval(this.intervaloTiempo);
        this.intervaloTiempo = null;
      }
    },

    tiempoAgotado() {
      this.mostrarAlerta('El tiempo ha finalizado. Guardando examen automáticamente...');
      this.finalizarExamen();
    }
  }
});

window.vm = app.mount('#app');