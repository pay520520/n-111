<?php

declare(strict_types=1);

use WHMCS\Database\Capsule;

class CfModuleInstaller
{
    public static function activate(): array
    {
            try {
                cfmod_ensure_provider_schema();
                // 创建主表（如果不存在）
                if (!Capsule::schema()->hasTable('mod_cloudflare_subdomain')) {
                    Capsule::schema()->create('mod_cloudflare_subdomain', function ($table) {
                        $table->increments('id');
                        $table->integer('userid')->unsigned();
                        $table->string('subdomain', 255);
                        $table->string('rootdomain', 255);
                        $table->integer('provider_account_id')->unsigned()->nullable();
                        $table->string('cloudflare_zone_id', 50);
                        $table->string('dns_record_id', 50)->nullable();
                        $table->string('status', 20)->default('active');
                        $table->dateTime('expires_at')->nullable();
                        $table->dateTime('renewed_at')->nullable();
                        $table->dateTime('auto_deleted_at')->nullable();
                        $table->boolean('never_expires')->default(0);
                        $table->text('notes')->nullable();
                        $table->integer('gift_lock_id')->unsigned()->nullable();
                        $table->timestamps();
        
                        // 添加索引
                        $table->index('userid');
                        $table->index('subdomain');
                        $table->index('status');
                        $table->index('rootdomain');
                        $table->index('provider_account_id');
                        $table->index(['expires_at', 'status'], 'idx_expiry_status');
                        $table->index('gift_lock_id');
                    });
                }
                if (Capsule::schema()->hasTable('mod_cloudflare_subdomain')) {
                    try {
                        if (!Capsule::schema()->hasColumn('mod_cloudflare_subdomain', 'expires_at')) {
                            Capsule::schema()->table('mod_cloudflare_subdomain', function ($table) {
                                $table->dateTime('expires_at')->nullable();
                            });
                        }
                        if (!Capsule::schema()->hasColumn('mod_cloudflare_subdomain', 'renewed_at')) {
                            Capsule::schema()->table('mod_cloudflare_subdomain', function ($table) {
                                $table->dateTime('renewed_at')->nullable();
                            });
                        }
                        if (!Capsule::schema()->hasColumn('mod_cloudflare_subdomain', 'auto_deleted_at')) {
                            Capsule::schema()->table('mod_cloudflare_subdomain', function ($table) {
                                $table->dateTime('auto_deleted_at')->nullable();
                            });
                        }
                        if (!Capsule::schema()->hasColumn('mod_cloudflare_subdomain', 'has_dns_history')) {
                            Capsule::schema()->table('mod_cloudflare_subdomain', function ($table) {
                                $table->boolean('has_dns_history')->default(0)->after('auto_deleted_at');
                            });
                        }
                        if (!Capsule::schema()->hasColumn('mod_cloudflare_subdomain', 'default_ns_mode')) {
                            Capsule::schema()->table('mod_cloudflare_subdomain', function ($table) {
                                $table->boolean('default_ns_mode')->default(1)->after('has_dns_history');
                                $table->index('default_ns_mode');
                            });
                        } elseif (!cf_index_exists('mod_cloudflare_subdomain', 'mod_cloudflare_subdomain_default_ns_mode_index')) {
                            Capsule::schema()->table('mod_cloudflare_subdomain', function ($table) {
                                $table->index('default_ns_mode');
                            });
                        }
                        if (!Capsule::schema()->hasColumn('mod_cloudflare_subdomain', 'never_expires')) {
                            Capsule::schema()->table('mod_cloudflare_subdomain', function ($table) {
                                $table->boolean('never_expires')->default(0);
                            });
                        }
                        if (!Capsule::schema()->hasColumn('mod_cloudflare_subdomain', 'gift_lock_id')) {
                            Capsule::schema()->table('mod_cloudflare_subdomain', function ($table) {
                                $table->integer('gift_lock_id')->unsigned()->nullable()->after('notes');
                                $table->index('gift_lock_id');
                            });
                        } elseif (!cf_index_exists('mod_cloudflare_subdomain', 'mod_cloudflare_subdomain_gift_lock_id_index')) {
                            Capsule::schema()->table('mod_cloudflare_subdomain', function ($table) {
                                $table->index('gift_lock_id');
                            });
                        }
                        if (!Capsule::schema()->hasColumn('mod_cloudflare_subdomain', 'provider_account_id')) {
                            Capsule::schema()->table('mod_cloudflare_subdomain', function ($table) {
                                $table->integer('provider_account_id')->unsigned()->nullable()->after('rootdomain');
                                $table->index('provider_account_id');
                            });
                        } elseif (!cf_index_exists('mod_cloudflare_subdomain', 'mod_cloudflare_subdomain_provider_account_id_index')) {
                            Capsule::schema()->table('mod_cloudflare_subdomain', function ($table) {
                                $table->index('provider_account_id');
                            });
                        }
                        if (!cf_index_exists('mod_cloudflare_subdomain', 'idx_expiry_status')) {
                            Capsule::statement('ALTER TABLE `mod_cloudflare_subdomain` ADD INDEX `idx_expiry_status` (`expires_at`, `status`)');
                        }
                    } catch (\Exception $e) {}
                    try {
                        Capsule::table('mod_cloudflare_subdomain')
                            ->whereNull('expires_at')
                            ->update(['never_expires' => 1]);
                    } catch (\Exception $e) {}
                }
                if (Capsule::schema()->hasColumn('mod_cloudflare_subdomain', 'has_dns_history')
                    && Capsule::schema()->hasTable('mod_cloudflare_dns_records')) {
                    try {
                        Capsule::statement('UPDATE `mod_cloudflare_subdomain` AS s SET `has_dns_history` = 1 WHERE (`has_dns_history` IS NULL OR `has_dns_history` = 0) AND EXISTS (SELECT 1 FROM `mod_cloudflare_dns_records` AS r WHERE r.`subdomain_id` = s.`id` LIMIT 1)');
                    } catch (\Throwable $ignored) {}
                }
                try {
                    $defaultProviderIdSetting = cf_get_module_settings_cached()['default_provider_account_id'] ?? null;
                    if (is_numeric($defaultProviderIdSetting) && (int)$defaultProviderIdSetting > 0) {
                        Capsule::table('mod_cloudflare_subdomain')
                            ->whereNull('provider_account_id')
                            ->update(['provider_account_id' => (int) $defaultProviderIdSetting]);
                    }
                } catch (\Throwable $ignored) {}
        
                // 创建用户配额表（如果不存在）
                if (!Capsule::schema()->hasTable('mod_cloudflare_subdomain_quotas')) {
                    Capsule::schema()->create('mod_cloudflare_subdomain_quotas', function ($table) {
                        $table->increments('id');
                        $table->integer('userid')->unsigned()->unique();
                        $table->bigInteger('used_count')->default(0); // 改为bigInteger支持大数值
                        $table->bigInteger('max_count')->default(5); // 改为bigInteger支持最大99999999999
                        // 邀请奖励相关字段
                        $table->bigInteger('invite_bonus_count')->default(0); // 改为bigInteger
                        $table->bigInteger('invite_bonus_limit')->default(5); // 改为bigInteger
                        $table->timestamps();
        
                        $table->index('userid');
                    });
                }
        
                // （向后兼容）已有表则补充新增字段
                try {
                    if (Capsule::schema()->hasTable('mod_cloudflare_subdomain_quotas')) {
                        if (!Capsule::schema()->hasColumn('mod_cloudflare_subdomain_quotas', 'invite_bonus_count')) {
                            Capsule::schema()->table('mod_cloudflare_subdomain_quotas', function ($table) {
                                $table->bigInteger('invite_bonus_count')->default(0);
                            });
                        }
                        if (!Capsule::schema()->hasColumn('mod_cloudflare_subdomain_quotas', 'invite_bonus_limit')) {
                            Capsule::schema()->table('mod_cloudflare_subdomain_quotas', function ($table) {
                                $table->bigInteger('invite_bonus_limit')->default(5);
                            });
                        }
                    }
                } catch (\Exception $e) {}

                // 额度兑换码表
                if (!Capsule::schema()->hasTable('mod_cloudflare_quota_codes')) {
                    Capsule::schema()->create('mod_cloudflare_quota_codes', function ($table) {
                        $table->increments('id');
                        $table->string('code', 191)->unique();
                        $table->integer('grant_amount')->unsigned()->default(1);
                        $table->string('mode', 20)->default('single_use');
                        $table->integer('max_total_uses')->unsigned()->default(1);
                        $table->integer('per_user_limit')->unsigned()->default(1);
                        $table->tinyInteger('same_type_limit_enabled')->unsigned()->default(0);
                        $table->string('same_type_key', 64)->nullable();
                        $table->integer('redeemed_total')->unsigned()->default(0);
                        $table->dateTime('valid_from')->nullable();
                        $table->dateTime('valid_to')->nullable();
                        $table->string('status', 20)->default('active');
                        $table->string('batch_tag', 64)->nullable();
                        $table->integer('created_by_admin_id')->unsigned()->nullable();
                        $table->text('notes')->nullable();
                        $table->timestamps();
                        $table->index('status');
                        $table->index('valid_to');
                        $table->index('batch_tag');
                        $table->index(['same_type_limit_enabled', 'same_type_key'], 'idx_quota_codes_same_type');
                    });
                }

                if (!Capsule::schema()->hasTable('mod_cloudflare_quota_redemptions')) {
                    Capsule::schema()->create('mod_cloudflare_quota_redemptions', function ($table) {
                        $table->increments('id');
                        $table->integer('code_id')->unsigned();
                        $table->string('code', 191);
                        $table->integer('userid')->unsigned();
                        $table->integer('grant_amount')->unsigned()->default(1);
                        $table->string('status', 20)->default('success');
                        $table->text('message')->nullable();
                        $table->bigInteger('before_quota')->default(0);
                        $table->bigInteger('after_quota')->default(0);
                        $table->tinyInteger('same_type_limit_enabled')->unsigned()->default(0);
                        $table->string('same_type_key', 64)->nullable();
                        $table->string('client_ip', 45)->nullable();
                        $table->timestamps();
                        $table->index('code_id');
                        $table->index('userid');
                        $table->index('code');
                        $table->index('status');
                        $table->index('created_at');
                        $table->index(['userid', 'same_type_limit_enabled', 'same_type_key'], 'idx_quota_redeems_same_type');
                    });
                }

                try {
                    if (Capsule::schema()->hasTable('mod_cloudflare_quota_codes')) {
                        if (!Capsule::schema()->hasColumn('mod_cloudflare_quota_codes', 'same_type_limit_enabled')) {
                            Capsule::schema()->table('mod_cloudflare_quota_codes', function ($table) {
                                $table->tinyInteger('same_type_limit_enabled')->unsigned()->default(0)->after('per_user_limit');
                            });
                        }
                        if (!Capsule::schema()->hasColumn('mod_cloudflare_quota_codes', 'same_type_key')) {
                            Capsule::schema()->table('mod_cloudflare_quota_codes', function ($table) {
                                $table->string('same_type_key', 64)->nullable()->after('same_type_limit_enabled');
                            });
                        }
                        try {
                            Capsule::statement('ALTER TABLE `mod_cloudflare_quota_codes` ADD INDEX `idx_quota_codes_same_type` (`same_type_limit_enabled`, `same_type_key`)');
                        } catch (\Throwable $ignored) {
                        }
                    }

                    if (Capsule::schema()->hasTable('mod_cloudflare_quota_redemptions')) {
                        if (!Capsule::schema()->hasColumn('mod_cloudflare_quota_redemptions', 'same_type_limit_enabled')) {
                            Capsule::schema()->table('mod_cloudflare_quota_redemptions', function ($table) {
                                $table->tinyInteger('same_type_limit_enabled')->unsigned()->default(0)->after('after_quota');
                            });
                        }
                        if (!Capsule::schema()->hasColumn('mod_cloudflare_quota_redemptions', 'same_type_key')) {
                            Capsule::schema()->table('mod_cloudflare_quota_redemptions', function ($table) {
                                $table->string('same_type_key', 64)->nullable()->after('same_type_limit_enabled');
                            });
                        }
                        try {
                            Capsule::statement('ALTER TABLE `mod_cloudflare_quota_redemptions` ADD INDEX `idx_quota_redeems_same_type` (`userid`, `same_type_limit_enabled`, `same_type_key`)');
                        } catch (\Throwable $ignored) {
                        }
                    }
                } catch (\Throwable $ignored) {
                }
        
                // 特权用户表（如果不存在）
                if (!Capsule::schema()->hasTable('mod_cloudflare_special_users')) {
                    Capsule::schema()->create('mod_cloudflare_special_users', function ($table) {
                        $table->increments('id');
                        $table->integer('userid')->unsigned()->unique();
                        $table->string('notes', 255)->nullable();
                        $table->timestamps();
                        $table->index('userid');
                        $table->index('created_at');
                    });
                }
        
                // 邀请码表（如果不存在）
                if (!Capsule::schema()->hasTable('mod_cloudflare_invitation_codes')) {
                    Capsule::schema()->create('mod_cloudflare_invitation_codes', function ($table) {
                        $table->increments('id');
                        $table->integer('userid')->unsigned()->unique();
                        $table->string('code', 64)->unique();
                        $table->timestamps();
                        $table->index('userid');
                        $table->index('code');
                    });
                }
        
                // 邀请使用记录表（如果不存在）
                if (!Capsule::schema()->hasTable('mod_cloudflare_invitation_claims')) {
                    Capsule::schema()->create('mod_cloudflare_invitation_claims', function ($table) {
                        $table->increments('id');
                        $table->integer('inviter_userid')->unsigned();
                        $table->integer('invitee_userid')->unsigned();
                        $table->string('code', 64);
                        $table->timestamps();
                        $table->index('inviter_userid');
                        $table->index('invitee_userid');
                        $table->index('code');
                    });
                }
        
                // 邀请排行榜表（如果不存在）
                if (!Capsule::schema()->hasTable('mod_cloudflare_invite_leaderboard')) {
                    Capsule::schema()->create('mod_cloudflare_invite_leaderboard', function ($table) {
                        $table->increments('id');
                        $table->date('period_start');
                        $table->date('period_end');
                        $table->text('top_json')->nullable();
                        $table->timestamps();
                        $table->index(['period_start', 'period_end']);
                    });
                }
        
                // 邀请奖励表（如果不存在）
                if (!Capsule::schema()->hasTable('mod_cloudflare_invite_rewards')) {
                    Capsule::schema()->create('mod_cloudflare_invite_rewards', function ($table) {
                        $table->increments('id');
                        $table->date('period_start');
                        $table->date('period_end');
                        $table->integer('inviter_userid')->unsigned();
                        $table->string('code', 64);
                        $table->integer('rank')->default(0);
                        $table->integer('count')->default(0);
                        $table->string('status', 20)->default('eligible'); // eligible, claimed, expired
                        $table->timestamps();
                        $table->index(['period_start', 'period_end']);
                        $table->index('inviter_userid');
                        $table->index('status');
                    });
                }
        
                // 根域名表（用于后台管理允许注册的根域名）（如果不存在）
                if (!Capsule::schema()->hasTable('mod_cloudflare_rootdomains')) {
                    Capsule::schema()->create('mod_cloudflare_rootdomains', function ($table) {
                        $table->increments('id');
                        $table->string('domain', 255)->unique();
                        $table->integer('provider_account_id')->unsigned()->nullable();
                        $table->string('cloudflare_zone_id', 50)->nullable();
                        $table->string('status', 20)->default('active');
                        $table->boolean('maintenance')->default(0);
                        $table->integer('display_order')->default(0);
                        $table->text('description')->nullable();
                        $table->integer('max_subdomains')->default(1000);
                        $table->integer('per_user_limit')->default(0);
                        $table->integer('default_term_years')->default(0);
                        $table->timestamps();
                        $table->index('status');
                        $table->index('provider_account_id');
                    });
                } else {
                    try {
                        if (!Capsule::schema()->hasColumn('mod_cloudflare_rootdomains', 'per_user_limit')) {
                            Capsule::schema()->table('mod_cloudflare_rootdomains', function ($table) {
                                $table->integer('per_user_limit')->default(0)->after('max_subdomains');
                            });
                        }
                        if (!Capsule::schema()->hasColumn('mod_cloudflare_rootdomains', 'default_term_years')) {
                            Capsule::schema()->table('mod_cloudflare_rootdomains', function ($table) {
                                $table->integer('default_term_years')->default(0)->after('per_user_limit');
                            });
                        }
                        $addedDisplayOrderColumn = false;
                        if (!Capsule::schema()->hasColumn('mod_cloudflare_rootdomains', 'display_order')) {
                            Capsule::schema()->table('mod_cloudflare_rootdomains', function ($table) {
                                $table->integer('display_order')->default(0)->after('status');
                            });
                            $addedDisplayOrderColumn = true;
                        }
                        if ($addedDisplayOrderColumn) {
                            try {
                                Capsule::statement('UPDATE `mod_cloudflare_rootdomains` SET `display_order` = `id` WHERE `display_order` IS NULL OR `display_order` = 0');
                            } catch (\Throwable $ignored) {}
                        }
                        if (!Capsule::schema()->hasColumn('mod_cloudflare_rootdomains', 'provider_account_id')) {
                            Capsule::schema()->table('mod_cloudflare_rootdomains', function ($table) {
                                $table->integer('provider_account_id')->unsigned()->nullable()->after('domain');
                                $table->index('provider_account_id');
                            });
                        } elseif (!cf_index_exists('mod_cloudflare_rootdomains', 'mod_cloudflare_rootdomains_provider_account_id_index')) {
                            Capsule::schema()->table('mod_cloudflare_rootdomains', function ($table) {
                                $table->index('provider_account_id');
                            });
                        }
                        // 添加维护模式字段
                        if (!Capsule::schema()->hasColumn('mod_cloudflare_rootdomains', 'maintenance')) {
                            Capsule::schema()->table('mod_cloudflare_rootdomains', function ($table) {
                                $table->boolean('maintenance')->default(0)->after('status');
                            });
                        }
                        if (!Capsule::schema()->hasColumn('mod_cloudflare_rootdomains', 'disable_ns_management')) {
                            Capsule::schema()->table('mod_cloudflare_rootdomains', function ($table) {
                                $table->boolean('disable_ns_management')->default(0)->after('maintenance');
                            });
                        }
                        // 添加根域名邀请注册功能字段
                        if (!Capsule::schema()->hasColumn('mod_cloudflare_rootdomains', 'require_invite_code')) {
                            Capsule::schema()->table('mod_cloudflare_rootdomains', function ($table) {
                                $table->boolean('require_invite_code')->default(0)->after('maintenance');
                            });
                        }
                    } catch (\Throwable $e) {
                        // ignore schema alteration errors
                    }
                }
                try {
                    $defaultProviderIdSetting = cf_get_module_settings_cached()['default_provider_account_id'] ?? null;
                    if (is_numeric($defaultProviderIdSetting) && (int)$defaultProviderIdSetting > 0) {
                        Capsule::table('mod_cloudflare_rootdomains')
                            ->whereNull('provider_account_id')
                            ->update(['provider_account_id' => (int) $defaultProviderIdSetting]);
                    }
                } catch (\Throwable $ignored) {}
        
                // 操作日志表（记录注册与解析变更）（如果不存在）
                if (!Capsule::schema()->hasTable('mod_cloudflare_logs')) {
                    Capsule::schema()->create('mod_cloudflare_logs', function ($table) {
                        $table->increments('id');
                        $table->integer('userid')->unsigned()->nullable();
                        $table->integer('subdomain_id')->unsigned()->nullable();
                        $table->string('action', 100);
                        $table->text('details')->nullable();
                        $table->string('ip', 45)->nullable();
                        $table->string('user_agent')->nullable();
                        $table->timestamps();
                        $table->index('userid');
                        $table->index('subdomain_id');
                        $table->index('action');
                        $table->index('created_at');
                    });
                }
        
                if (!Capsule::schema()->hasTable('mod_cloudflare_domain_gifts')) {
                    Capsule::schema()->create('mod_cloudflare_domain_gifts', function ($table) {
                        $table->increments('id');
                        $table->string('code', 32)->unique();
                        $table->integer('subdomain_id')->unsigned();
                        $table->integer('from_userid')->unsigned();
                        $table->integer('to_userid')->unsigned()->nullable();
                        $table->string('full_domain', 255);
                        $table->string('status', 20)->default('pending');
                        $table->dateTime('expires_at');
                        $table->dateTime('completed_at')->nullable();
                        $table->dateTime('cancelled_at')->nullable();
                        $table->integer('cancelled_by_admin')->unsigned()->nullable();
                        $table->timestamps();
                        $table->index('subdomain_id');
                        $table->index('from_userid');
                        $table->index('to_userid');
                        $table->index('status');
                        $table->index('expires_at');
                    });
                }

                if (!Capsule::schema()->hasTable('mod_cloudflare_dns_unlock_codes')) {
                    Capsule::schema()->create('mod_cloudflare_dns_unlock_codes', function ($table) {
                        $table->increments('id');
                        $table->integer('userid')->unsigned()->unique();
                        $table->string('unlock_code', 16)->unique();
                        $table->dateTime('unlocked_at')->nullable();
                        $table->timestamps();
                        $table->index('unlock_code');
                    });
                }

                if (!Capsule::schema()->hasTable('mod_cloudflare_dns_unlock_logs')) {
                    Capsule::schema()->create('mod_cloudflare_dns_unlock_logs', function ($table) {
                        $table->increments('id');
                        $table->integer('unlock_code_id')->unsigned();
                        $table->integer('owner_userid')->unsigned();
                        $table->integer('used_userid')->unsigned()->nullable();
                        $table->string('used_email', 191)->nullable();
                        $table->string('used_ip', 64)->nullable();
                        $table->timestamps();
                        $table->index('unlock_code_id');
                        $table->index('owner_userid');
                        $table->index('used_userid');
                        $table->index('used_email');
                    });
                }

                // 邀请注册解锁表（如果不存在）
                if (!Capsule::schema()->hasTable('mod_cloudflare_invite_registration_unlock')) {
                    Capsule::schema()->create('mod_cloudflare_invite_registration_unlock', function ($table) {
                        $table->increments('id');
                        $table->integer('userid')->unsigned()->unique();
                        $table->string('invite_code', 20)->unique();
                        $table->integer('code_generate_count')->unsigned()->default(1);
                        $table->dateTime('unlocked_at')->nullable();
                        $table->timestamps();
                        $table->index('invite_code');
                    });
                }

                // 邀请注册日志表（如果不存在）
                if (!Capsule::schema()->hasTable('mod_cloudflare_invite_registration_logs')) {
                    Capsule::schema()->create('mod_cloudflare_invite_registration_logs', function ($table) {
                        $table->increments('id');
                        $table->integer('invite_code_id')->unsigned();
                        $table->integer('inviter_userid')->unsigned();
                        $table->integer('invitee_userid')->unsigned()->nullable();
                        $table->string('invitee_email', 191)->nullable();
                        $table->string('invitee_ip', 64)->nullable();
                        $table->string('invite_code', 20);
                        $table->timestamps();
                        $table->index('invite_code_id');
                        $table->index('inviter_userid');
                        $table->index('invitee_userid');
                        $table->index('invitee_email');
                        $table->index('invite_code');
                        $table->index('created_at');
                    });
                }

                if (!Capsule::schema()->hasTable('mod_cloudflare_domain_permanent_upgrade_requests')) {
                    Capsule::schema()->create('mod_cloudflare_domain_permanent_upgrade_requests', function ($table) {
                        $table->increments('id');
                        $table->integer('userid')->unsigned();
                        $table->integer('subdomain_id')->unsigned()->unique();
                        $table->string('assist_code', 20)->unique();
                        $table->integer('target_assists')->unsigned()->default(3);
                        $table->integer('assist_count')->unsigned()->default(0);
                        $table->string('status', 20)->default('pending');
                        $table->dateTime('upgraded_at')->nullable();
                        $table->timestamps();
                        $table->index('userid');
                        $table->index('status');
                        $table->index('created_at');
                    });
                }

                if (!Capsule::schema()->hasTable('mod_cloudflare_domain_permanent_upgrade_assists')) {
                    Capsule::schema()->create('mod_cloudflare_domain_permanent_upgrade_assists', function ($table) {
                        $table->increments('id');
                        $table->integer('request_id')->unsigned();
                        $table->integer('helper_userid')->unsigned();
                        $table->string('helper_email', 191)->nullable();
                        $table->string('helper_ip', 64)->nullable();
                        $table->string('assist_code', 20);
                        $table->timestamps();
                        $table->unique(['request_id', 'helper_userid'], 'uniq_cf_perm_upgrade_helper_once');
                        $table->index('request_id');
                        $table->index('helper_userid');
                        $table->index('assist_code');
                        $table->index('created_at');
                    });
                }

                if (!Capsule::schema()->hasTable('mod_cloudflare_invite_registration_github_bindings')) {
                    Capsule::schema()->create('mod_cloudflare_invite_registration_github_bindings', function ($table) {
                        $table->increments('id');
                        $table->integer('userid')->unsigned()->unique();
                        $table->bigInteger('github_id')->unsigned()->unique();
                        $table->string('github_login', 191)->nullable();
                        $table->string('github_name', 191)->nullable();
                        $table->dateTime('github_created_at')->nullable();
                        $table->string('avatar_url', 255)->nullable();
                        $table->timestamps();
                        $table->index('userid');
                        $table->index('github_id');
                    });
                }

                // 根域名邀请码表（如果不存在）
                if (!Capsule::schema()->hasTable('mod_cloudflare_rootdomain_invite_codes')) {
                    Capsule::schema()->create('mod_cloudflare_rootdomain_invite_codes', function ($table) {
                        $table->increments('id');
                        $table->integer('userid')->unsigned();
                        $table->string('rootdomain', 255);
                        $table->string('invite_code', 10)->unique();
                        $table->integer('code_generate_count')->unsigned()->default(1);
                        $table->timestamps();
                        $table->unique(['userid', 'rootdomain']);
                        $table->index('rootdomain');
                        $table->index('userid');
                        $table->index('invite_code');
                    });
                }

                // 根域名邀请注册日志表（如果不存在）
                if (!Capsule::schema()->hasTable('mod_cloudflare_rootdomain_invite_logs')) {
                    Capsule::schema()->create('mod_cloudflare_rootdomain_invite_logs', function ($table) {
                        $table->increments('id');
                        $table->string('rootdomain', 255);
                        $table->string('invite_code', 10);
                        $table->integer('inviter_userid')->unsigned();
                        $table->integer('invitee_userid')->unsigned()->nullable();
                        $table->string('invitee_email', 191)->nullable();
                        $table->string('subdomain', 255)->nullable();
                        $table->string('invitee_ip', 64)->nullable();
                        $table->timestamps();
                        $table->index('rootdomain');
                        $table->index('invite_code');
                        $table->index('inviter_userid');
                        $table->index('invitee_userid');
                        $table->index('invitee_email');
                        $table->index('created_at');
                    });
                }
        
                // 禁止域名表（黑名单）（如果不存在）
                if (!Capsule::schema()->hasTable('mod_cloudflare_forbidden_domains')) {
                    Capsule::schema()->create('mod_cloudflare_forbidden_domains', function ($table) {
                        $table->increments('id');
                        $table->string('domain', 255)->unique();
                        $table->string('rootdomain', 255)->nullable();
                        $table->string('reason', 255)->nullable();
                        $table->string('added_by', 100)->nullable();
                        $table->timestamps();
                        $table->index('rootdomain');
                    });
                }
        
                // DNS记录表（用户可管理的记录）（如果不存在）
                if (!Capsule::schema()->hasTable('mod_cloudflare_dns_records')) {
                    Capsule::schema()->create('mod_cloudflare_dns_records', function ($table) {
                        $table->increments('id');
                        $table->integer('subdomain_id')->unsigned();
                        $table->string('zone_id', 50);
                        $table->string('record_id', 50); // Cloudflare 返回的记录ID
                        $table->string('name', 255); // 完整记录名，如 aaa.foo.example.com 或 foo.example.com
                        $table->string('type', 10);
                        $table->text('content');
                        $table->integer('ttl')->default(120);
                        $table->boolean('proxied')->default(false);
                        $table->string('line', 32)->nullable();
                        $table->string('status', 20)->default('active');
                        $table->integer('priority')->nullable(); // 用于MX等记录
                        $table->timestamps();
                        $table->index('subdomain_id');
                        $table->index('record_id');
                        $table->index('name');
                        $table->index('type');
                    });
                }
        
                // 队列表（如果不存在）
                if (!Capsule::schema()->hasTable('mod_cloudflare_jobs')) {
                    Capsule::schema()->create('mod_cloudflare_jobs', function ($table) {
                        $table->increments('id');
                        $table->string('type', 50);
                        $table->text('payload_json');
                        $table->integer('priority')->default(10);
                        $table->string('status', 20)->default('pending');
                        $table->integer('attempts')->default(0);
                        $table->dateTime('next_run_at')->nullable();
                        $table->dateTime('started_at')->nullable();
                        $table->dateTime('heartbeat_at')->nullable();
                        $table->dateTime('finished_at')->nullable();
                        $table->integer('duration_seconds')->nullable();
                        $table->string('lease_token', 64)->nullable();
                        $table->string('worker_id', 64)->nullable();
                        $table->boolean('cancel_requested')->default(false);
                        $table->dateTime('cancel_requested_at')->nullable();
                        $table->text('last_error')->nullable();
                        $table->longText('stats_json')->nullable();
                        $table->timestamps();
                        $table->index('status');
                        $table->index('type');
                        $table->index('priority');
                        $table->index('next_run_at');
                        $table->index('heartbeat_at');
                        $table->index('lease_token');
                    });
                }
        
                // 队列表新增控制与指标字段
                try {
                    if (Capsule::schema()->hasTable('mod_cloudflare_jobs')) {
                        if (!Capsule::schema()->hasColumn('mod_cloudflare_jobs', 'started_at')) {
                            Capsule::schema()->table('mod_cloudflare_jobs', function($table) {
                                $table->dateTime('started_at')->nullable()->after('next_run_at');
                            });
                        }
                        if (!Capsule::schema()->hasColumn('mod_cloudflare_jobs', 'heartbeat_at')) {
                            Capsule::schema()->table('mod_cloudflare_jobs', function($table) {
                                $table->dateTime('heartbeat_at')->nullable()->after('started_at');
                            });
                        }
                        if (!Capsule::schema()->hasColumn('mod_cloudflare_jobs', 'finished_at')) {
                            Capsule::schema()->table('mod_cloudflare_jobs', function($table) {
                                $table->dateTime('finished_at')->nullable()->after('heartbeat_at');
                            });
                        }
                        if (!Capsule::schema()->hasColumn('mod_cloudflare_jobs', 'duration_seconds')) {
                            Capsule::schema()->table('mod_cloudflare_jobs', function($table) {
                                $table->integer('duration_seconds')->nullable()->after('finished_at');
                            });
                        }
                        if (!Capsule::schema()->hasColumn('mod_cloudflare_jobs', 'lease_token')) {
                            Capsule::schema()->table('mod_cloudflare_jobs', function($table) {
                                $table->string('lease_token', 64)->nullable()->after('duration_seconds');
                            });
                        }
                        if (!Capsule::schema()->hasColumn('mod_cloudflare_jobs', 'worker_id')) {
                            Capsule::schema()->table('mod_cloudflare_jobs', function($table) {
                                $table->string('worker_id', 64)->nullable()->after('lease_token');
                            });
                        }
                        if (!Capsule::schema()->hasColumn('mod_cloudflare_jobs', 'cancel_requested')) {
                            Capsule::schema()->table('mod_cloudflare_jobs', function($table) {
                                $table->boolean('cancel_requested')->default(false)->after('worker_id');
                            });
                        }
                        if (!Capsule::schema()->hasColumn('mod_cloudflare_jobs', 'cancel_requested_at')) {
                            Capsule::schema()->table('mod_cloudflare_jobs', function($table) {
                                $table->dateTime('cancel_requested_at')->nullable()->after('cancel_requested');
                            });
                        }
                        if (!Capsule::schema()->hasColumn('mod_cloudflare_jobs', 'stats_json')) {
                            Capsule::schema()->table('mod_cloudflare_jobs', function($table) {
                                $table->longText('stats_json')->nullable()->after('last_error');
                            });
                        }
                        if (!Capsule::schema()->hasColumn('mod_cloudflare_jobs', 'subdomain_id')) {
                            Capsule::schema()->table('mod_cloudflare_jobs', function($table) {
                                $table->integer('subdomain_id')->unsigned()->nullable()->after('payload_json');
                            });
                        }
                        if (Capsule::schema()->hasColumn('mod_cloudflare_jobs', 'subdomain_id')) {
                            if (!cf_index_exists('mod_cloudflare_jobs', 'idx_cf_jobs_type_status_subdomain')) {
                                Capsule::statement('ALTER TABLE `mod_cloudflare_jobs` ADD INDEX `idx_cf_jobs_type_status_subdomain` (`type`, `status`, `subdomain_id`)');
                            }
                            if (!cf_index_exists('mod_cloudflare_jobs', 'idx_cf_jobs_subdomain_status')) {
                                Capsule::statement('ALTER TABLE `mod_cloudflare_jobs` ADD INDEX `idx_cf_jobs_subdomain_status` (`subdomain_id`, `status`)');
                            }
                        }
                        if (Capsule::schema()->hasColumn('mod_cloudflare_jobs', 'started_at')) {
                            if (!cf_index_exists('mod_cloudflare_jobs', 'idx_cf_jobs_started_at')) {
                                Capsule::statement('ALTER TABLE `mod_cloudflare_jobs` ADD INDEX `idx_cf_jobs_started_at` (`started_at`)');
                            }
                            if (!cf_index_exists('mod_cloudflare_jobs', 'idx_cf_jobs_status_type_started_at')) {
                                Capsule::statement('ALTER TABLE `mod_cloudflare_jobs` ADD INDEX `idx_cf_jobs_status_type_started_at` (`status`, `type`, `started_at`)');
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    // ignore schema migration errors
                }
        
                // 校准结果表（如果不存在）
                if (!Capsule::schema()->hasTable('mod_cloudflare_sync_results')) {
                    Capsule::schema()->create('mod_cloudflare_sync_results', function ($table) {
                        $table->increments('id');
                        $table->integer('job_id')->unsigned();
                        $table->integer('subdomain_id')->unsigned()->nullable();
                        $table->string('kind', 50); // missing_on_cf / extra_on_cf / mismatch
                        $table->string('action', 50); // created_on_cf / deleted_on_cf / updated_on_cf / noop
                        $table->text('detail')->nullable();
                        $table->timestamps();
                        $table->index('job_id');
                        $table->index('subdomain_id');
                        $table->index('kind');
                    });
                }
        
                // 用户操作统计表（修复闭包嵌套错误）（如果不存在）
                if (!Capsule::schema()->hasTable('mod_cloudflare_user_stats')) {
                    Capsule::schema()->create('mod_cloudflare_user_stats', function ($table) {
                        $table->increments('id');
                        $table->integer('userid')->unsigned();
                        $table->integer('subdomains_created')->default(0);
                        $table->integer('dns_records_created')->default(0);
                        $table->integer('dns_records_updated')->default(0);
                        $table->integer('dns_records_deleted')->default(0);
                        $table->dateTime('last_activity')->nullable();
                        $table->timestamps();
                        $table->index('userid');
                    });
                }
        
                // 用户封禁表（如果不存在）
                if (!Capsule::schema()->hasTable('mod_cloudflare_user_bans')) {
                    Capsule::schema()->create('mod_cloudflare_user_bans', function ($table) {
                        $table->increments('id');
                        $table->integer('userid')->unsigned();
                        $table->text('ban_reason');
                        $table->string('banned_by', 100);
                        $table->dateTime('banned_at');
                        $table->dateTime('unbanned_at')->nullable();
                        $table->string('status', 20)->default('banned'); // banned, unbanned
                        // 封禁类型与到期时间（用于临时/每周封禁自动解封）
                        $table->string('ban_type', 20)->default('permanent'); // permanent, temporary, weekly
                        $table->dateTime('ban_expires_at')->nullable();
                        $table->timestamps();
                        $table->index('userid');
                        $table->index('status');
                        $table->index('banned_at');
                    });
                }
        
                // 风险表：域名风险概览（如果不存在）
                if (!Capsule::schema()->hasTable('mod_cloudflare_domain_risk')) {
                    Capsule::schema()->create('mod_cloudflare_domain_risk', function ($table) {
                        $table->increments('id');
                        $table->integer('subdomain_id')->unsigned();
                        $table->integer('risk_score')->default(0);
                        $table->string('risk_level', 16)->default('low');
                        $table->text('reasons_json')->nullable();
                        $table->dateTime('last_checked_at')->nullable();
                        $table->timestamps();
                        $table->unique('subdomain_id');
                        $table->index(['risk_score','risk_level']);
                    });
                }
        
                // 风险事件流水（如果不存在）
                if (!Capsule::schema()->hasTable('mod_cloudflare_risk_events')) {
                    Capsule::schema()->create('mod_cloudflare_risk_events', function ($table) {
                        $table->increments('id');
                        $table->integer('subdomain_id')->unsigned();
                        $table->string('source', 32); // url_probe / abuseipdb / spamhaus / otx
                        $table->integer('score')->default(0);
                        $table->string('level', 16)->default('low');
                        $table->string('reason', 255)->nullable();
                        $table->text('details_json')->nullable();
                        $table->timestamps();
                        $table->index(['subdomain_id','created_at']);
                        $table->index(['level','created_at']);
                    });
                }
        
                // API密钥表（如果不存在）
                if (!Capsule::schema()->hasTable('mod_cloudflare_api_keys')) {
                    Capsule::schema()->create('mod_cloudflare_api_keys', function ($table) {
                        $table->increments('id');
                        $table->integer('userid')->unsigned();
                        $table->string('key_name', 100); // 密钥名称
                        $table->string('api_key', 64)->unique(); // API密钥
                        $table->string('api_secret', 128); // API密钥（加密存储）
                        $table->string('status', 20)->default('active'); // active, disabled, disabled_by_ban
                        $table->text('ip_whitelist')->nullable(); // IP白名单，逗号分隔
                        $table->text('permissions')->nullable(); // 权限JSON
                        $table->integer('request_count')->default(0); // 总请求次数
                        $table->integer('rate_limit')->default(60); // 速率限制（每分钟请求数）
                        $table->dateTime('last_used_at')->nullable(); // 最后使用时间
                        $table->dateTime('expires_at')->nullable(); // 过期时间
                        $table->timestamps();
                        $table->index('userid');
                        $table->index('api_key');
                        $table->index('status');
                    });
                } else {
                    // 如果表已存在，检查并添加rate_limit字段
                    if (!Capsule::schema()->hasColumn('mod_cloudflare_api_keys', 'rate_limit')) {
                        Capsule::schema()->table('mod_cloudflare_api_keys', function ($table) {
                            $table->integer('rate_limit')->default(60)->after('request_count');
                        });
                    }
                }
        
                // API请求日志表（如果不存在）
                if (!Capsule::schema()->hasTable('mod_cloudflare_api_logs')) {
                    Capsule::schema()->create('mod_cloudflare_api_logs', function ($table) {
                        $table->increments('id');
                        $table->integer('api_key_id')->unsigned();
                        $table->integer('userid')->unsigned();
                        $table->string('endpoint', 100); // API端点
                        $table->string('method', 10); // GET/POST/PUT/DELETE
                        $table->text('request_data')->nullable(); // 请求数据
                        $table->text('response_data')->nullable(); // 响应数据
                        $table->integer('response_code')->default(200); // HTTP响应码
                        $table->string('ip', 45); // 请求IP
                        $table->string('user_agent')->nullable();
                        $table->decimal('execution_time', 8, 3)->default(0); // 执行时间（秒）
                        $table->timestamps();
                        $table->index('api_key_id');
                        $table->index('userid');
                        $table->index('endpoint');
                        $table->index('created_at');
                    });
                }
        
                // API速率限制表（如果不存在）
                if (!Capsule::schema()->hasTable('mod_cloudflare_api_rate_limit')) {
                    Capsule::schema()->create('mod_cloudflare_api_rate_limit', function ($table) {
                        $table->increments('id');
                        $table->integer('api_key_id')->unsigned();
                        $table->string('window_key', 100); // 时间窗口键（如：key_123_2025-10-19_14:30）
                        $table->integer('request_count')->default(0);
                        $table->dateTime('window_start');
                        $table->dateTime('window_end');
                        $table->timestamps();
                        $table->unique(['api_key_id', 'window_key'], 'uniq_cf_api_rate_window');
                        $table->index('window_end');
                    });
                } else {
                    if (!cf_index_exists('mod_cloudflare_api_rate_limit', 'uniq_cf_api_rate_window')) {
                        try {
                            $duplicates = Capsule::table('mod_cloudflare_api_rate_limit')
                                ->select('api_key_id', 'window_key', Capsule::raw('COUNT(*) as cnt'))
                                ->groupBy('api_key_id', 'window_key')
                                ->having('cnt', '>', 1)
                                ->get();
                            if ($duplicates && count($duplicates) > 0) {
                                foreach ($duplicates as $dup) {
                                    $rows = Capsule::table('mod_cloudflare_api_rate_limit')
                                        ->where('api_key_id', $dup->api_key_id)
                                        ->where('window_key', $dup->window_key)
                                        ->orderBy('id', 'asc')
                                        ->get();
                                    if (!$rows || count($rows) === 0) {
                                        continue;
                                    }
                                    $keepRow = null;
                                    $deleteIds = [];
                                    $extraCount = 0;
                                    foreach ($rows as $index => $row) {
                                        if ($index === 0) {
                                            $keepRow = $row;
                                            continue;
                                        }
                                        $deleteIds[] = intval($row->id);
                                        $extraCount += intval($row->request_count ?? 0);
                                    }
                                    if ($keepRow) {
                                        if ($extraCount > 0) {
                                            $newCount = intval($keepRow->request_count ?? 0) + $extraCount;
                                            Capsule::table('mod_cloudflare_api_rate_limit')
                                                ->where('id', $keepRow->id)
                                                ->update([
                                                    'request_count' => $newCount,
                                                    'updated_at' => date('Y-m-d H:i:s'),
                                                ]);
                                        }
                                    }
                                    if (!empty($deleteIds)) {
                                        Capsule::table('mod_cloudflare_api_rate_limit')
                                            ->whereIn('id', $deleteIds)
                                            ->delete();
                                    }
                                }
                            }
                        } catch (\Throwable $cleanupException) {
                            error_log('[domain_hub][activate] duplicate rate limit cleanup failed: ' . $cleanupException->getMessage());
                        }
                        Capsule::statement('ALTER TABLE `mod_cloudflare_api_rate_limit` ADD UNIQUE INDEX `uniq_cf_api_rate_window` (`api_key_id`, `window_key`)');
                    }
                }

                if (!Capsule::schema()->hasTable('mod_cloudflare_job_locks')) {
                    Capsule::schema()->create('mod_cloudflare_job_locks', function ($table) {
                        $table->increments('id');
                        $table->string('lock_key', 191);
                        $table->string('job_type', 64);
                        $table->string('scope_key', 191);
                        $table->timestamps();
                        $table->unique('lock_key', 'uniq_cf_job_lock_key');
                        $table->unique(['job_type', 'scope_key'], 'uniq_cf_job_lock_type_scope');
                        $table->index('updated_at', 'idx_cf_job_lock_updated');
                    });
                }
        
                if (!Capsule::schema()->hasTable('mod_cloudflare_rate_limits')) {
                    Capsule::schema()->create('mod_cloudflare_rate_limits', function ($table) {
                        $table->increments('id');
                        $table->string('scope', 64);
                        $table->string('bucket', 191);
                        $table->integer('hits')->default(1);
                        $table->dateTime('expires_at')->nullable();
                        $table->timestamps();
                        $table->unique(['scope', 'bucket'], 'uniq_cf_rate_scope_bucket');
                        $table->index('expires_at', 'idx_cf_rate_expires');
                    });
                }

                if (!Capsule::schema()->hasTable('mod_cloudflare_user_preferences')) {
                    Capsule::schema()->create('mod_cloudflare_user_preferences', function ($table) {
                        $table->increments('id');
                        $table->integer('userid')->unsigned()->unique();
                        $table->string('renew_button_display_mode', 32)->default('window_only');
                        $table->timestamps();
                        $table->index(['renew_button_display_mode']);
                    });
                } elseif (!Capsule::schema()->hasColumn('mod_cloudflare_user_preferences', 'renew_button_display_mode')) {
                    Capsule::schema()->table('mod_cloudflare_user_preferences', function ($table) {
                        $table->string('renew_button_display_mode', 32)->default('window_only');
                    });
                }
        
                // 公共 WHOIS 速率限制表
                if (!Capsule::schema()->hasTable('mod_cloudflare_whois_rate_limit')) {
                    Capsule::schema()->create('mod_cloudflare_whois_rate_limit', function ($table) {
                        $table->increments('id');
                        $table->string('ip', 45);
                        $table->string('window_key', 64);
                        $table->integer('request_count')->default(0);
                        $table->dateTime('window_start');
                        $table->dateTime('window_end');
                        $table->timestamps();
                        $table->unique(['ip', 'window_key'], 'uniq_cf_whois_ip_window');
                        $table->index('window_end');
                    });
                } else {
                    if (!cf_index_exists('mod_cloudflare_whois_rate_limit', 'uniq_cf_whois_ip_window')) {
                        Capsule::statement('ALTER TABLE `mod_cloudflare_whois_rate_limit` ADD UNIQUE INDEX `uniq_cf_whois_ip_window` (`ip`, `window_key`)');
                    }
                }

                // VPN/代理检测缓存表
                if (!Capsule::schema()->hasTable('mod_cloudflare_vpn_cache')) {
                    Capsule::schema()->create('mod_cloudflare_vpn_cache', function ($table) {
                        $table->increments('id');
                        $table->string('ip_hash', 64)->unique();
                        $table->tinyInteger('is_blocked')->default(0);
                        $table->string('reason', 32)->nullable();
                        $table->tinyInteger('is_vpn')->default(0);
                        $table->tinyInteger('is_proxy')->default(0);
                        $table->tinyInteger('is_hosting')->default(0);
                        $table->dateTime('checked_at');
                        $table->dateTime('expires_at');
                        $table->dateTime('created_at');
                        $table->index('expires_at');
                    });
                }
        
                try {
                    cfmod_sync_default_provider_account(cf_get_module_settings_cached());
                } catch (\Throwable $ignored) {
                }
        
                // 🚀 性能优化：自动添加性能优化索引
                $indexesAdded = cf_add_performance_indexes();
                $indexMsg = $indexesAdded > 0 ? "，已添加{$indexesAdded}个性能优化索引" : "";
        
                return ['status'=>'success','description'=>'插件激活成功，数据库表已创建/更新，所有数据已保留' . $indexMsg];
            } catch (\Exception $e) {
                return ['status'=>'error','description'=>'数据库创建失败: '.$e->getMessage()];
            }
    }

    public static function deactivate(): array
    {
            try {
                // 可以选择是否删除表，这里保留数据
                return ['status'=>'success','description'=>'插件已停用，数据已保留'];
            } catch (\Exception $e) {
                return ['status'=>'error','description'=>'插件停用失败: '.$e->getMessage()];
            }
    }

    public static function uninstall(): array
    {
            try {
                Capsule::schema()->dropIfExists('mod_cloudflare_subdomain');
                Capsule::schema()->dropIfExists('mod_cloudflare_subdomain_quotas');
                Capsule::schema()->dropIfExists('mod_cloudflare_rootdomains');
                Capsule::schema()->dropIfExists('mod_cloudflare_logs');
                Capsule::schema()->dropIfExists('mod_cloudflare_forbidden_domains');
                Capsule::schema()->dropIfExists('mod_cloudflare_dns_records');
                Capsule::schema()->dropIfExists('mod_cloudflare_jobs');
                Capsule::schema()->dropIfExists('mod_cloudflare_sync_results');
                Capsule::schema()->dropIfExists('mod_cloudflare_user_stats');
                Capsule::schema()->dropIfExists('mod_cloudflare_user_bans');
                Capsule::schema()->dropIfExists('mod_cloudflare_domain_risk');
                Capsule::schema()->dropIfExists('mod_cloudflare_risk_events');
                Capsule::schema()->dropIfExists('mod_cloudflare_invitation_codes');
                Capsule::schema()->dropIfExists('mod_cloudflare_invitation_claims');
                Capsule::schema()->dropIfExists('mod_cloudflare_invite_leaderboard');
                Capsule::schema()->dropIfExists('mod_cloudflare_invite_rewards');
                Capsule::schema()->dropIfExists('mod_cloudflare_invite_registration_github_bindings');
                Capsule::schema()->dropIfExists('mod_cloudflare_domain_permanent_upgrade_assists');
                Capsule::schema()->dropIfExists('mod_cloudflare_domain_permanent_upgrade_requests');
                Capsule::schema()->dropIfExists('mod_cloudflare_special_users');
                Capsule::schema()->dropIfExists('mod_cloudflare_api_keys');
                Capsule::schema()->dropIfExists('mod_cloudflare_api_logs');
                Capsule::schema()->dropIfExists('mod_cloudflare_api_rate_limit');
                Capsule::schema()->dropIfExists('mod_cloudflare_rate_limits');
                Capsule::schema()->dropIfExists('mod_cloudflare_whois_rate_limit');
                Capsule::schema()->dropIfExists('mod_cloudflare_vpn_cache');
                Capsule::schema()->dropIfExists('mod_cloudflare_provider_accounts');
                return ['status'=>'success','description'=>'插件已完全卸载，数据已删除'];
            } catch (\Exception $e) {
                return ['status'=>'error','description'=>'插件卸载失败: '.$e->getMessage()];
            }
    }
}
