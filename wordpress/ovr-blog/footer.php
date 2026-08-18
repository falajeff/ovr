<?php if (!defined('ABSPATH')) exit; ?>
</main>

<footer class="rodape">
  <div class="envelope">
    <div class="rodape__grade">
      <div class="rodape__marca">
        <?php ovr_marca('accent'); ?>
        <p class="t-corpo" style="max-width:38ch">Custom goods para marcas, pessoas e ideias em movimento. DTF + DTG / Brasil.</p>
      </div>
      <div><h4>Comprar</h4><ul>
        <li><a href="<?php echo esc_url(OVR_SITE); ?>/catalogo.html">Catálogo</a></li>
        <li><a href="<?php echo esc_url(OVR_SITE); ?>/impressao-especial.html">Impressão especial</a></li>
      </ul></div>
      <div><h4>Ajuda</h4><ul>
        <li><a href="<?php echo esc_url(OVR_SITE); ?>/como-funciona.html">Como funciona</a></li>
        <li><a href="<?php echo esc_url(OVR_SITE); ?>/guia-de-arte.html">Guia de arte</a></li>
        <li><a href="<?php echo esc_url(home_url('/')); ?>">Blog</a></li>
      </ul></div>
      <div><h4>Contato</h4><ul>
        <li><a href="<?php echo esc_url(ovr_zap()); ?>" target="_blank" rel="noopener">WhatsApp</a></li>
        <li><a href="https://instagram.com/<?php echo esc_attr(OVR_INSTAGRAM); ?>" target="_blank" rel="noopener">Instagram</a></li>
        <li><a href="mailto:<?php echo esc_attr(OVR_EMAIL); ?>"><?php echo esc_html(OVR_EMAIL); ?></a></li>
      </ul></div>
    </div>
    <div class="rodape__fim">
      <p class="t-meta">OVR® / Custom goods / <?php echo esc_html(date('Y')); ?></p>
      <p class="t-meta">Frete por conta do cliente. Grátis acima de R$ 1.500 para SP, PR, RJ e SC.</p>
    </div>
  </div>
</footer>
<?php wp_footer(); ?>
<script>
/* menu do celular */
(function(){
  var m = document.querySelector('[data-menu]');
  if (!m) return;
  var abrir = function(v){ m.dataset.aberto = v; m.setAttribute('aria-hidden', String(!v)); document.body.style.overflow = v ? 'hidden' : ''; };
  document.querySelectorAll('[data-menu-abrir]').forEach(function(b){ b.addEventListener('click', function(){ abrir(true); }); });
  m.querySelectorAll('[data-menu-fechar], a').forEach(function(b){ b.addEventListener('click', function(){ abrir(false); }); });
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape') abrir(false); });
  abrir(false);
})();
</script>
</body>
</html>
