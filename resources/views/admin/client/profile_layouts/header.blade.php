      <!-- Header -->
      <div class="row">
                <div class="col-12">
                  <div class="card mb-6">
                    <div class="user-profile-header-banner">
                      <img src="/assets/img/pages/profile-banner.png" alt="Banner image" class="rounded-top w-100" />
                    </div>
                    <div class="user-profile-header px-5 py-4 d-flex flex-column flex-lg-row text-sm-start text-center">
                      <div class="flex-shrink-0 mt-n2 mx-sm-0 mx-auto">
                      @php
                            $initials = collect(explode(' ', $client->name))->map(fn($word) => strtoupper($word[0]))->join('');
                        @endphp

                        <div class="avatar-placeholder rounded d-flex align-items-center justify-content-center bg-label-primary"
                            style="height: 90px; width: 90px; color: #fff; font-size: 28px; font-weight: bold;">
                            {{ $initials }}
                        </div>
                      </div>
                      <div class="flex-grow-1">
                        <div
                          class="d-flex align-items-md-end align-items-sm-start align-items-center justify-content-md-between justify-content-start mx-5 flex-md-row flex-column gap-4">
                          <div class="user-profile-info">
                            <h4 class="mb-2">{{$client->name}}</h4>
                            <ul class="list-inline mb-0 d-flex align-items-center flex-wrap justify-content-sm-start justify-content-center gap-1" style='font-size:13px;'>
                                <li class="list-inline-item">
                                    <span class="fw-medium">{{ $client->email }}</span>
                                </li>
                                <li class="list-inline-item">|</li>

                                <li class="list-inline-item">
                                    <span class="fw-medium">{{ $client->phone_number }}</span>
                                </li>
                                <li class="list-inline-item">|</li>
                                <li class="list-inline-item">
                                    <span class="fw-medium">Joined {{ $client->created_at->format('F Y') }}</span>
                                </li>
                            </ul>

                          </div>
                          <a href="javascript:void(0)" class="btn btn-primary mb-1">
                            <i class="icon-base ti tabler-user-check icon-xs me-2"></i>Connected
                          </a>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
              <!--/ Header -->