import { useEffect, useState } from 'react';
import { AvatarMedia } from './ui';
import '../../css/profile-photo-picker.css';

/** Controlled picker. Remove is staged until the parent form is saved. */
export default function ProfilePhotoPicker({ current, file, removed = false, onFile, onRemove, label = 'Profile photo', fallback = '?', accept = 'image/jpeg,image/png', maxMB = 0.8 }) {
  const [preview, setPreview] = useState('');
  useEffect(() => {
    if (!file) { setPreview(''); return; }
    const url = URL.createObjectURL(file);
    setPreview(url);
    return () => URL.revokeObjectURL(url);
  }, [file]);
  const displayed = preview || (!removed && current) || '';
  const choose = event => {
    const selected = event.target.files?.[0];
    event.target.value = '';
    if (!selected) return;
    if (!['image/jpeg','image/png','image/webp'].includes(selected.type)) { window.alert('Choose a JPG, PNG or WebP image.'); return; }
    if (selected.size > maxMB * 1024 * 1024) { window.alert(`Image must be no larger than ${maxMB} MB.`); return; }
    onFile(selected);
  };
  return <div className="cvma-photo-picker">
    <div className="cvma-photo-picker-preview"><AvatarMedia src={displayed} alt={label} fallback={fallback}/></div>
    <div className="cvma-photo-picker-actions">
      <strong>{label}</strong><span>{file ? `Ready to upload: ${file.name}` : removed ? 'Photo will be removed when saved' : displayed ? 'Current photo' : 'No photo selected'}</span>
      <div className="cvma-photo-picker-buttons">
        <label className="upload-button">{displayed ? 'Change photo' : 'Choose photo'}<input type="file" accept={accept} onChange={choose}/></label>
        {(file || (!removed && current)) && <button className="cvma-photo-remove" type="button" onClick={onRemove}>Remove photo</button>}
      </div>
      <small>JPG / PNG{accept.includes('webp') ? ' / WebP' : ''} · max {maxMB} MB. Save the form to apply changes.</small>
    </div>
  </div>;
}
