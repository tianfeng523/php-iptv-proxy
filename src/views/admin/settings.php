                    <div class="mb-3">
                        <label for="monitor_refresh_interval" class="form-label">监控刷新时间（秒）</label>
                        <input type="number" class="form-control" id="monitor_refresh_interval" name="monitor_refresh_interval" value="<?= $settings['monitor_refresh_interval'] ?? 5 ?>" min="1">
                    </div>

                    <div class="mb-3">
                        <label for="max_error_count" class="form-label">最大异常次数</label>
                        <input type="number" class="form-control" id="max_error_count" name="max_error_count" value="<?= $settings['max_error_count'] ?? 3 ?>" min="1">
                        <div class="form-text">当频道检查异常次数达到此值时，将自动删除该频道</div>
                    </div>

                    <div class="mb-3">