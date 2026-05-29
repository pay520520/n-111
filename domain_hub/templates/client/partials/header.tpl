<?php
$headerTitle = htmlspecialchars(cfmod_trans('cfclient.header_title', 'DNSHE免费域名管理平台'), ENT_QUOTES);
$headerSubtitle = htmlspecialchars(cfmod_trans('cfclient.header_subtitle', '管理您的免费域名，设置DNS解析。'), ENT_QUOTES);
$announceFallbackTitle = htmlspecialchars(cfmod_trans('cfclient.announcement_title', '公告'), ENT_QUOTES);
$announceButtonText = htmlspecialchars(cfmod_trans('cfclient.announcement_confirm', '我知道了'), ENT_QUOTES);
$clientAnnounceTitleSafe = (isset($clientAnnounceTitle) && $clientAnnounceTitle !== '')
    ? htmlspecialchars($clientAnnounceTitle, ENT_QUOTES)
    : $announceFallbackTitle;
?>
<?php echo $cfmodClientNoscriptNotice ?? ''; ?>
<div class="main-container">
    <?php
        $languageOptions = isset($availableLanguages) && is_array($availableLanguages) ? $availableLanguages : [];
        $languageSwitchLabel = cfclient_lang('cfclient.language.switch_label', '选择语言', [], true);
        $activeLanguageLabel = $languageSwitchLabel;
        foreach ($languageOptions as $langOption) {
            if (!empty($langOption['active'])) {
                $activeLanguageLabel = htmlspecialchars($langOption['label'], ENT_QUOTES);
                break;
            }
        }
    ?>
    <!-- 头部区域 -->
    <div class="header-section text-center position-relative">
        <div class="position-absolute top-0 end-0 d-flex gap-2 align-items-start">
            <?php if (!empty($languageOptions)): ?>
                <div class="header-language-switcher">
                    <div class="btn-group">
                        <button class="btn btn-light btn-sm dropdown-toggle cfmod-lang-toggle" 
                                type="button" 
                                id="cfmodLanguageDropdown">
                            <i class="fas fa-language me-1"></i> <span class="cfmod-lang-current"><?php echo $activeLanguageLabel; ?></span>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end cfmod-lang-menu" style="display:none;">
                            <h6 class="dropdown-header text-muted small"><?php echo $languageSwitchLabel; ?></h6>
                            <?php foreach ($languageOptions as $langOption): ?>
                                <a class="dropdown-item <?php echo !empty($langOption['active']) ? 'active fw-bold' : ''; ?>" 
                                   href="<?php echo htmlspecialchars($langOption['url'], ENT_QUOTES); ?>">
                                    <?php echo htmlspecialchars($langOption['label'], ENT_QUOTES); ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <style>
                .cfmod-lang-menu {
                    position: absolute;
                    top: 100%;
                    right: 0;
                    z-index: 1050;
                    min-width: 10rem;
                    padding: 0.5rem 0;
                    margin: 0.125rem 0 0;
                    font-size: 1rem;
                    color: #212529;
                    text-align: left;
                    list-style: none;
                    background-color: #fff;
                    background-clip: padding-box;
                    border: 1px solid rgba(0,0,0,.15);
                    border-radius: 0.25rem;
                    box-shadow: 0 0.5rem 1rem rgba(0,0,0,.175);
                }
                .cfmod-lang-menu.show {
                    display: block !important;
                }
                .header-language-switcher {
                    position: relative;
                }
                </style>
                <script>
                (function() {
                    var toggleBtn = document.getElementById('cfmodLanguageDropdown');
                    var menu = document.querySelector('.cfmod-lang-menu');
                    
                    if (toggleBtn && menu) {
                        toggleBtn.addEventListener('click', function(e) {
                            e.preventDefault();
                            e.stopPropagation();
                            menu.classList.toggle('show');
                        });
                        
                        document.addEventListener('click', function(e) {
                            if (!toggleBtn.contains(e.target) && !menu.contains(e.target)) {
                                menu.classList.remove('show');
                            }
                        });
                        
                        var menuItems = menu.querySelectorAll('.dropdown-item');
                        menuItems.forEach(function(item) {
                            item.addEventListener('click', function() {
                                menu.classList.remove('show');
                            });
                        });
                    }
                })();
                </script>
            <?php endif; ?>
        </div>
        <h1><i class="fas fa-globe"></i> <?php echo $headerTitle; ?></h1>
        <p class="mb-0"><?php echo $headerSubtitle; ?></p>
    </div>
    <?php if ($clientAnnounceEnabled): ?>
        <div class="modal fade" id="clientAnnounceModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-bullhorn me-2"></i><?php echo $clientAnnounceTitleSafe; ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div><?php echo $clientAnnounceHtml; ?></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-primary" data-bs-dismiss="modal" id="clientAnnounceDismiss"><?php echo $announceButtonText; ?></button>
                    </div>
                </div>
            </div>
        </div>
        <script>
            (function(){
                try{
                    var key=<?php echo json_encode($clientAnnounceCookieKey, CFMOD_SAFE_JSON_FLAGS); ?>;
                    if (document.cookie.indexOf(key+'=1')!==-1) return;
                    var show=function(){ var el=document.getElementById('clientAnnounceModal'); if(!el) return; var m=new bootstrap.Modal(el); m.show(); };
                    if (document.readyState==='complete') { setTimeout(show, 0); } else { window.addEventListener('load', function(){ setTimeout(show, 0); }); }
                    var btn=document.getElementById('clientAnnounceDismiss');
                    if (btn) btn.addEventListener('click', function(){
                        var d=new Date(); d.setFullYear(d.getFullYear()+1);
                        document.cookie = key+'=1; path=/; expires='+d.toUTCString();
                    });
                }catch(e){}
            })();
        </script>
    <?php endif; ?>
