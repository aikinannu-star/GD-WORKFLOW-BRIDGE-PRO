<!-- Revision Request Form Template -->
<div class="gdwb-form-revision-request">
    <h3><?php esc_html_e('Request a Revision', 'gdwb'); ?></h3>
    <form id="revision-request-form" data-project-id="<?php echo intval($project_id); ?>">
        <div class="form-group">
            <label><?php esc_html_e('Revision Title', 'gdwb'); ?></label>
            <input type="text" name="title" placeholder="<?php esc_attr_e('e.g., Change color scheme', 'gdwb'); ?>" required>
        </div>

        <div class="form-group">
            <label><?php esc_html_e('Detailed Description', 'gdwb'); ?></label>
            <textarea name="description" rows="5" placeholder="<?php esc_attr_e('Describe what changes you need...', 'gdwb'); ?>" required></textarea>
        </div>

        <div class="form-group">
            <label><?php esc_html_e('Priority', 'gdwb'); ?></label>
            <select name="priority">
                <option value="low"><?php esc_html_e('Low', 'gdwb'); ?></option>
                <option value="medium" selected><?php esc_html_e('Medium', 'gdwb'); ?></option>
                <option value="high"><?php esc_html_e('High', 'gdwb'); ?></option>
            </select>
        </div>

        <button type="submit" class="btn btn-primary"><?php esc_html_e('Submit Revision Request', 'gdwb'); ?></button>
        <span class="spinner" style="display:none;"></span>
    </form>
    <div class="message" style="display:none;"></div>
</div>
