<!DOCTYPE html>
<html>

<head>
	<meta charset="utf-8">
	<meta http-equiv="X-UA-Compatible" content="IE=edge">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="stylesheet" type="text/css" href="login.css">
	<link rel="icon" href="imgs/favicon.png" type="image/x-icon">
	<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
	<script src="login_index.js"></script>
	<title>NOROAÇO</title>
	<style>
		.caps-lock-msg {
			color: red;
			display: none;
			margin-top: 15px;
			text-align: center;
			font-size: 14px;
			font-weight: bold;
		}
	</style>
</head>

<body>
	<section class="material-half-bg">
		<div class="cover"></div>
	</section>
	<section class="login-content">
		<div class="login-box">
			<div class="login-form">
				<h3 class="login-head">
					<img src="imgs/logo.png" alt="Logo" style="height: 80px;">
				</h3>

				<div class="form-group">
					<label class="control-label">Login</label>
					<input id="login" name="loginUsuario" type="text" placeholder="Login" autofocus class="form-control">
				</div>

				<div class="form-group password-wrapper">
					<label class="control-label">Senha</label>
					<div style="position: relative;">
						<input id="senha" name="senhaUsuario" type="password" placeholder="Senha" class="form-control">
						<i id="toggleSenha" class="fa-solid fa-eye" style="position: absolute; right: 10px; top: 60%; transform: translateY(-50%); cursor: pointer;"></i>
					</div>
				</div>

				<!-- Mensagem única de Caps Lock -->
				<span id="capsLockMessage" class="caps-lock-msg">Caps Lock está ativado</span>

				<div class="form-group btn-container">
					<button onclick="login()" class="btn btn-primary btn-block"> E N T R A R </button>
				</div>
			</div>
		</div>
	</section>

	<!-- Modal de Mensagens -->
	<div id="modalMensagem" class="modal" style="display: none;">
		<div class="modal-content mensagem-modal">
			<div class="mensagem-icon">
				<i id="mensagem-icone" class="fas"></i>
			</div>
			<div class="mensagem-conteudo">
				<h2 id="mensagem-titulo"></h2>
				<p id="mensagem-texto"></p>
			</div>
			<div class="mensagem-botoes">
				<button id="mensagem-btn-ok" class="btn btn-primary">OK</button>
			</div>
		</div>
	</div>
</body>

</html>

<script type="text/javascript">
	$(document).ready(function () {
		// Pressionar Enter ativa login
		$('.login-form input').on("keypress", function (e) {
			if (e.key === "Enter" || e.keyCode === 13) {
				login();
			}
		});

		// Alternar visibilidade da senha
		document.getElementById('toggleSenha').addEventListener('click', function () {
			const senhaInput = document.getElementById('senha');
			const icon = this;

			if (senhaInput.type === 'password') {
				senhaInput.type = 'text';
				icon.classList.remove('fa-eye');
				icon.classList.add('fa-eye-slash');
			} else {
				senhaInput.type = 'password';
				icon.classList.remove('fa-eye-slash');
				icon.classList.add('fa-eye');
			}
		});

		// Função para mostrar/esconder aviso
		let capsLockDetectado = false;
		function checkCapsLock(e) {
        const isCapsLock = e.getModifierState && e.getModifierState('CapsLock');
        const mensagem = document.getElementById('capsLockMessage');

        mensagem.style.display = isCapsLock ? 'block' : 'none';
    }

    // Verificar Caps Lock quando o campo recebe foco
    document.getElementById('login').addEventListener('focus', function() {
        // Dispara um evento de tecla fictício para verificação
        const fakeEvent = {
            getModifierState: function(mod) {
                return mod === 'CapsLock' ? 
                    (window.event && window.event.getModifierState('CapsLock')) : false;
            }
        };
        checkCapsLock(fakeEvent);
    });

    document.getElementById('senha').addEventListener('focus', function() {
        // Mesma verificação para o campo de senha
        const fakeEvent = {
            getModifierState: function(mod) {
                return mod === 'CapsLock' ? 
                    (window.event && window.event.getModifierState('CapsLock')) : false;
            }
        };
        checkCapsLock(fakeEvent);
    });

    // Detectar ao digitar
    document.getElementById('login').addEventListener('keyup', checkCapsLock);
    document.getElementById('senha').addEventListener('keyup', checkCapsLock);
});
</script>
