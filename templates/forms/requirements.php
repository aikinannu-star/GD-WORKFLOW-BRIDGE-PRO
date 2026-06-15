<!-- Requirements Upload Form Template -->
<div class="gdwb-form-requirements">
    <h3><?php esc_html_e('Submit Requirements', 'gdwb'); ?></h3>
    <form id="requirements-form" data-project-id="<?php echo intval($project_id); ?>" enctype="multipart/form-data">
        <div class="form-group">
            <label><?php esc_html_e('Project Requirements', 'gdwb'); ?></label>
            <textarea name="requirements" rows="6" placeholder="<?php esc_attr_e('Describe your project requirements in detail...', 'gdwb'); ?>" required></textarea>
        </div>

        <div class="form-group">
            <label><?php esc_html_e('Expected Deadline', 'gdwb'); ?></label>
            <input type="date" name="deadline" required>
        </div>

        <div class="form-group">
            <label><?php esc_html_e('Attachment (Optional)', 'gdwb'); ?></label>
            <input type="file" name="attachment" accept=".pdf,.doc,.docx,.txt,.zip">
            <small><?php esc_html_e('Max file size: 50MB. Allowed: PDF, DOC, DOCX, TXT, ZIP', 'gdwb'); ?></small>
        </div>

        <button type="submit" class="btn btn-primary"><?php esc_html_e('Submit Requirements', 'gdwb'); ?></button>
        <span class="spinner" style="display:none;"></span>
    </form>
    <div class="message" style="display:none;"></div>
</div>
